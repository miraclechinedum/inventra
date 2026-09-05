<?php

namespace App\Actions\Sale;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalePaymentType;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SalePaymentRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\PaymentNumber;
use App\Support\SaleFinancials;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordSalePayment
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Sale $sale, array $data, string $sessionId): SalePayment
    {
        if (Gate::forUser($actor)->denies('recordPayment', $sale)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $sale, $data, $sessionId): SalePayment {
            $request = SalePaymentRequest::query()->where('token_hash', hash('sha256', $data['request_token']))->lockForUpdate()->first();
            if (! $request || $request->sale_id !== $sale->id || $request->actor_id !== $actor->id
                || ! hash_equals($request->session_id, $sessionId)) {
                throw ValidationException::withMessages(['request_token' => 'This payment confirmation has expired. Refresh the Sale and try again.']);
            }
            if ($request->used_at !== null) {
                return SalePayment::query()->findOrFail($request->sale_payment_id);
            }
            if ($request->expires_at->isPast()) {
                throw ValidationException::withMessages(['request_token' => 'This payment confirmation has expired. Refresh the Sale and try again.']);
            }

            $lockedSale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            if ($lockedSale->status !== SaleStatus::Completed) {
                throw ValidationException::withMessages(['sale' => 'Payments can only be recorded for completed Sales.']);
            }
            if ($lockedSale->customer_id === null) {
                throw ValidationException::withMessages(['sale' => 'The Sale customer relationship is invalid.']);
            }

            $financials = SaleFinancials::lockedState($lockedSale);
            $ledgerTotal = $financials['payments'];
            if (bccomp($ledgerTotal, $lockedSale->amount_paid, 2) !== 0) {
                throw ValidationException::withMessages(['sale' => 'Payment history does not match the Sale balance.']);
            }

            $amount = Money::round($data['amount']);
            if (bccomp($lockedSale->balance_due, '0.00', 2) <= 0 || $lockedSale->payment_status === PaymentStatus::Paid) {
                throw ValidationException::withMessages(['sale' => 'This Sale has no outstanding balance.']);
            }
            if (bccomp($amount, $lockedSale->balance_due, 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the outstanding balance.']);
            }

            $cumulative = bcadd($ledgerTotal, $amount, 2);
            $netCash = bcsub($cumulative, $financials['refunds'], 2);
            $balance = bcsub($financials['obligation'], $netCash, 2);
            $status = bccomp($balance, '0.00', 2) === 0 ? PaymentStatus::Paid : PaymentStatus::Partial;

            $payment = new SalePayment;
            $payment->payment_number = 'PENDING-'.Str::random(20);
            $payment->sale_id = $lockedSale->id;
            $payment->customer_id = $lockedSale->customer_id;
            $payment->amount = $amount;
            $payment->payment_method = PaymentMethod::from($data['payment_method']);
            $payment->payment_type = SalePaymentType::Settlement;
            $payment->recorded_by = $actor->id;
            $payment->recorded_by_name_snapshot = $actor->name;
            $payment->paid_at = now();
            $payment->note = $data['note'] ?? null;
            $payment->cumulative_paid_after = $cumulative;
            $payment->balance_after = $balance;
            $payment->payment_status_after = $status;
            $payment->initial_sale_guard = null;
            $payment->save();
            $payment->payment_number = PaymentNumber::fromId($payment->id);
            $payment->save();

            $lockedSale->synchronizeReturnFinancials(SaleFinancials::lockedState($lockedSale));
            $request->used_at = now();
            $request->sale_payment_id = $payment->id;
            $request->save();

            $this->audit->record('sale_payment_recorded', $payment, $actor, newValues: [
                'sale_id' => $lockedSale->id,
                'sale_number' => $lockedSale->sale_number,
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'amount' => $amount,
                'payment_method' => $payment->payment_method->value,
                'resulting_payment_status' => $status->value,
            ]);

            return $payment;
        });
    }
}
