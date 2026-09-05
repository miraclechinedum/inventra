<?php

namespace App\Actions\Sale;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\SaleRefundRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\SaleFinancials;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordSaleRefund
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Sale $sale, array $data, string $sessionId): SaleRefund
    {
        if (! in_array($actor->role, [UserRole::Admin, UserRole::Manager], true)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $sale, $data, $sessionId) {
            $request = SaleRefundRequest::where('token_hash', hash('sha256', $data['request_token']))->lockForUpdate()->first();
            $payloadHash = hash('sha256', json_encode(['amount' => $data['amount'], 'payment_method' => $data['payment_method'], 'reason' => $data['reason'], 'note' => $data['note'] ?? null], JSON_THROW_ON_ERROR));
            if (! $request || (int) $request->sale_id !== (int) $sale->id || (int) $request->actor_id !== (int) $actor->id || ! hash_equals((string) $request->session_id, $sessionId)) {
                throw ValidationException::withMessages(['request_token' => 'This refund confirmation has expired. Refresh and try again.']);
            }if ($request->used_at) {
                if (! hash_equals((string) $request->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages(['request_token' => 'This refund confirmation was already used with different details.']);
                }

                return SaleRefund::findOrFail($request->sale_refund_id);
            }
            if ($request->expires_at->isPast()) {
                throw ValidationException::withMessages(['request_token' => 'This refund confirmation has expired. Refresh and try again.']);
            }
            $locked = Sale::lockForUpdate()->findOrFail($sale->id);
            if ($locked->status !== SaleStatus::Completed) {
                throw ValidationException::withMessages(['sale' => 'Refunds require a completed, non-voided Sale.']);
            }
            if (($data['sale_return_id'] ?? null) !== null && ! $locked->returns()->whereKey($data['sale_return_id'])->exists()) {
                throw ValidationException::withMessages(['sale_return_id' => 'The selected Return does not belong to this Sale.']);
            }
            $state = SaleFinancials::lockedState($locked);
            $amount = Money::round($data['amount']);
            if (bccomp($amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Refund amount must be greater than zero.']);
            }
            if (bccomp($amount, $state['credit'], 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'Refund cannot exceed the currently refundable customer credit.']);
            }
            $refund = new SaleRefund;
            $refund->sale_refund_request_id = $request->id;
            foreach (['refund_number' => 'PENDING-'.Str::random(20), 'sale_id' => $locked->id, 'sale_return_id' => $data['sale_return_id'] ?? null, 'customer_id' => $locked->customer_id, 'sale_number_snapshot' => $locked->sale_number, 'customer_code_snapshot' => $locked->customer_code_snapshot, 'customer_name_snapshot' => $locked->customer_name_snapshot, 'amount' => $amount, 'payment_method' => PaymentMethod::from($data['payment_method']), 'reason' => $data['reason'], 'note' => $data['note'] ?? null, 'refunded_by' => $actor->id, 'refunded_by_name_snapshot' => $actor->name, 'refunded_at' => now()] as $k => $v) {
                $refund->$k = $v;
            }$refund->save();
            $number = 'REF-'.str_pad((string) $refund->id, 6, '0', STR_PAD_LEFT);
            DB::table('sale_refunds')->where('id', $refund->id)->update(['refund_number' => $number]);
            $refund->setAttribute('refund_number', $number);
            $after = SaleFinancials::lockedState($locked);
            $locked->synchronizeReturnFinancials($after);
            $request->payload_hash = $payloadHash;
            $request->used_at = now();
            $request->sale_refund_id = $refund->id;
            $request->save();
            $this->audit->record('sale_refund_recorded', $refund, $actor, newValues: ['refund_id' => $refund->id, 'refund_number' => $number, 'sale_id' => $locked->id, 'sale_number' => $locked->sale_number, 'amount' => $amount, 'method' => $refund->payment_method->value, 'refunded_at' => $refund->refunded_at->toISOString()]);

            return $refund;
        });
    }
}
