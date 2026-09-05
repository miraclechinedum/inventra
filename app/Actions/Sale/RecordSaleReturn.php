<?php

namespace App\Actions\Sale;

use App\Enums\InventoryMovementType;
use App\Enums\ReturnDisposition;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SaleReturnRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\SaleFinancials;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordSaleReturn
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Sale $sale, array $data, string $sessionId): SaleReturn
    {
        if (! in_array($actor->role, [UserRole::Admin, UserRole::Manager], true)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $sale, $data, $sessionId): SaleReturn {
            $request = SaleReturnRequest::where('token_hash', hash('sha256', $data['request_token']))->lockForUpdate()->first();
            $payloadHash = hash('sha256', json_encode(['items' => $data['items'], 'reason' => $data['reason'], 'note' => $data['note'] ?? null], JSON_THROW_ON_ERROR));
            if (! $request || (int) $request->sale_id !== (int) $sale->id || (int) $request->actor_id !== (int) $actor->id || ! hash_equals((string) $request->session_id, $sessionId)) {
                throw ValidationException::withMessages(['request_token' => 'This return confirmation has expired. Refresh and try again.']);
            }
            if ($request->used_at) {
                if (! hash_equals((string) $request->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages(['request_token' => 'This return confirmation was already used with different details.']);
                }

                return SaleReturn::findOrFail($request->sale_return_id);
            }
            if ($request->expires_at->isPast()) {
                throw ValidationException::withMessages(['request_token' => 'This return confirmation has expired. Refresh and try again.']);
            }
            $locked = Sale::lockForUpdate()->findOrFail($sale->id);
            if ($locked->status !== SaleStatus::Completed) {
                throw ValidationException::withMessages(['sale' => 'Returns require a completed, non-voided Sale.']);
            }
            $ids = collect($data['items'])->pluck('sale_item_id');
            if ($ids->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => 'Each Sale item may appear only once.']);
            }
            $items = $locked->items()->whereIn('id', $ids)->orderBy('product_id')->lockForUpdate()->get()->keyBy('id');
            if ($items->count() !== $ids->count()) {
                throw ValidationException::withMessages(['items' => 'A selected item does not belong to this Sale.']);
            }
            $productIds = $items->pluck('product_id')->unique()->sort()->values();
            $products = Product::withTrashed()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages(['items' => 'A referenced Product is unavailable.']);
            }
            $prepared = [];
            $total = '0.00';
            foreach ($data['items'] as $line) {
                $item = $items[(int) $line['sale_item_id']];
                $prior = (string) DB::table('sale_return_items')->where('sale_item_id', $item->id)->lockForUpdate()->sum('quantity_returned');
                $remaining = bcsub($item->quantity, $prior, 3);
                if (bccomp($line['quantity'], $remaining, 3) > 0) {
                    throw ValidationException::withMessages(['items' => 'Returned quantity exceeds the remaining returnable quantity.']);
                } $value = Money::round(bcmul($item->unit_price, $line['quantity'], 5));
                if (bccomp($value, '0.00', 2) <= 0) {
                    throw ValidationException::withMessages(['items' => 'Returned merchandise must have a positive value.']);
                } $total = bcadd($total, $value, 2);
                $prepared[] = [$item, $line, $value];
            }
            $before = SaleFinancials::lockedState($locked);
            $reduction = bccomp($total, $before['balance'], 2) > 0 ? $before['balance'] : $total;
            $credit = bcsub($total, $reduction, 2);
            $return = new SaleReturn;
            $return->sale_return_request_id = $request->id;
            foreach (['return_number' => 'PENDING-'.Str::random(20), 'sale_id' => $locked->id, 'customer_id' => $locked->customer_id, 'sale_number_snapshot' => $locked->sale_number, 'customer_code_snapshot' => $locked->customer_code_snapshot, 'customer_name_snapshot' => $locked->customer_name_snapshot, 'returned_by' => $actor->id, 'returned_by_name_snapshot' => $actor->name, 'merchandise_value' => $total, 'receivable_reduction' => $reduction, 'refundable_credit_created' => $credit, 'reason' => $data['reason'], 'note' => $data['note'] ?? null, 'returned_at' => now()] as $k => $v) {
                $return->$k = $v;
            } $return->save();
            $number = 'RET-'.str_pad((string) $return->id, 6, '0', STR_PAD_LEFT);
            DB::table('sale_returns')->where('id', $return->id)->update(['return_number' => $number]);
            $return->setAttribute('return_number', $number);
            foreach ($prepared as [$item,$line,$value]) {
                $ri = new SaleReturnItem;
                foreach (['sale_return_id' => $return->id, 'sale_item_id' => $item->id, 'product_id' => $item->product_id, 'product_sku_snapshot' => $item->product_sku_snapshot, 'product_name_snapshot' => $item->product_name_snapshot, 'unit_snapshot' => $item->unit_snapshot, 'quantity_returned' => $line['quantity'], 'original_unit_price' => $item->unit_price, 'return_line_value' => $value, 'disposition' => $line['disposition'], 'created_at' => now()] as $k => $v) {
                    $ri->$k = $v;
                } $ri->save();
                if (ReturnDisposition::from($line['disposition']) === ReturnDisposition::Restock) {
                    $product = $products[$item->product_id];
                    $after = bcadd($product->current_stock, $line['quantity'], 3);
                    $m = new InventoryMovement;
                    foreach (['product_id' => $product->id, 'type' => InventoryMovementType::SaleReturn, 'quantity_change' => $line['quantity'], 'quantity_before' => $product->current_stock, 'quantity_after' => $after, 'reference_type' => $return->getMorphClass(), 'reference_id' => $return->id, 'reason' => 'Customer return', 'performed_by' => $actor->id] as $k => $v) {
                        $m->$k = $v;
                    }$m->save();
                    $product->current_stock = $after;
                    $product->updated_by = $actor->id;
                    $product->save();
                }
            }
            $after = SaleFinancials::lockedState($locked);
            $locked->synchronizeReturnFinancials($after);
            $request->payload_hash = $payloadHash;
            $request->used_at = now();
            $request->sale_return_id = $return->id;
            $request->save();
            $this->audit->record('sale_return_recorded', $return, $actor, newValues: ['return_id' => $return->id, 'return_number' => $number, 'sale_id' => $locked->id, 'sale_number' => $locked->sale_number, 'customer_id' => $locked->customer_id, 'item_count' => count($prepared), 'merchandise_value' => $total, 'receivable_reduction' => $reduction, 'returned_at' => $return->returned_at->toISOString()]);

            return $return;
        });
    }
}
