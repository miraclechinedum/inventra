<?php

namespace App\Actions\Purchase;

use App\Enums\InventoryMovementType;
use App\Enums\PurchaseStatus;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\PurchaseNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceivePurchase
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(User $actor, array $data, string $sessionId): Purchase
    {
        Gate::forUser($actor)->authorize('create', Purchase::class);

        return DB::transaction(function () use ($actor, $data, $sessionId) {
            $request = PurchaseRequest::query()->where('token_hash', hash('sha256', $data['request_token']))->lockForUpdate()->first();
            if (! $request || $request->actor_id !== $actor->id || ! hash_equals($request->session_id, $sessionId)) {
                throw ValidationException::withMessages(['request_token' => 'This receiving confirmation is invalid.']);
            }

            if ($request->used_at) {
                return Purchase::findOrFail($request->purchase_id);
            }

            if ($request->expires_at->isPast()) {
                throw ValidationException::withMessages(['request_token' => 'This receiving confirmation has expired.']);
            }

            $submittedLines = $data['items'] ?? [];
            $normalizedLines = is_array($submittedLines)
                ? array_values(array_filter($submittedLines, fn ($line) => is_array($line) && array_filter(
                    $line,
                    fn ($value) => $value !== null && $value !== ''
                ) !== []))
                : [];

            if ($normalizedLines === []) {
                throw ValidationException::withMessages(['items' => 'At least one Purchase item is required.']);
            }

            $supplier = Supplier::query()->lockForUpdate()->findOrFail($data['supplier_id']);
            if (! $supplier->is_active) {
                throw ValidationException::withMessages(['supplier_id' => 'The selected Supplier is inactive.']);
            }

            $ids = array_map(fn ($line) => (int) $line['product_id'], $normalizedLines);
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['items' => 'Duplicate Products are not allowed.']);
            }

            sort($ids, SORT_NUMERIC);
            $input = collect($normalizedLines)->keyBy(fn ($line) => (int) $line['product_id']);
            $products = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($products->count() !== count($ids)) {
                throw ValidationException::withMessages(['items' => 'One or more Products are unavailable.']);
            }

            $subtotal = '0.00';
            $lines = [];
            foreach ($ids as $id) {
                $product = $products[$id];
                if (! $product->is_active) {
                    throw ValidationException::withMessages(['items' => "{$product->name} is inactive."]);
                }

                $line = $input[$id];
                $quantity = bcadd($line['quantity'], '0', 3);
                $cost = bcadd($line['unit_cost'], '0', 2);
                $total = Money::round(bcmul($quantity, $cost, 5));
                $after = bcadd($product->current_stock, $quantity, 3);
                if (bccomp($after, '999999999999.999', 3) > 0) {
                    throw ValidationException::withMessages(['items' => 'The resulting stock exceeds the supported limit.']);
                }

                $subtotal = bcadd($subtotal, $total, 2);
                if (bccomp($subtotal, '9999999999999.99', 2) > 0) {
                    throw ValidationException::withMessages(['items' => 'The Purchase total exceeds the supported limit.']);
                }

                $lines[] = compact('product', 'quantity', 'cost', 'total', 'after');
            }

            $purchase = new Purchase;
            $purchase->purchase_number = 'PENDING-'.Str::random(20);
            $purchase->supplier_id = $supplier->id;
            $purchase->supplier_code_snapshot = $supplier->supplier_code;
            $purchase->supplier_name_snapshot = $supplier->name;
            $purchase->supplier_phone_snapshot = $supplier->phone;
            $purchase->subtotal = $subtotal;
            $purchase->discount_amount = '0.00';
            $purchase->total_amount = $subtotal;
            $purchase->reference_number = $data['reference_number'] ?? null;
            $purchase->note = $data['note'] ?? null;
            $purchase->status = PurchaseStatus::Received;
            $purchase->received_by = $actor->id;
            $purchase->received_by_name_snapshot = $actor->name;
            $receivedAt = now();
            $purchase->received_at = $receivedAt;
            $purchase->save();
            $purchase->purchase_number = PurchaseNumber::fromId($purchase->id);
            $purchase->save();
            foreach ($lines as $line) {
                $item = new PurchaseItem;
                $item->purchase_id = $purchase->id;
                $item->product_id = $line['product']->id;
                $item->product_sku_snapshot = $line['product']->sku;
                $item->product_name_snapshot = $line['product']->name;
                $item->product_unit_snapshot = $line['product']->unit->value;
                $item->quantity = $line['quantity'];
                $item->unit_cost = $line['cost'];
                $item->line_total = $line['total'];
                $item->created_at = $receivedAt;
                $item->save();

                $movement = new InventoryMovement;
                $movement->product_id = $line['product']->id;
                $movement->type = InventoryMovementType::Purchase;
                $movement->quantity_change = $line['quantity'];
                $movement->quantity_before = $line['product']->current_stock;
                $movement->quantity_after = $line['after'];
                $movement->reference_type = $purchase->getMorphClass();
                $movement->reference_id = $purchase->id;
                $movement->reason = 'Purchase '.$purchase->purchase_number.' from '.$purchase->supplier_name_snapshot;
                $movement->performed_by = $actor->id;
                $movement->save();

                $line['product']->current_stock = $line['after'];
                $line['product']->updated_by = $actor->id;
                $line['product']->save();
            }

            $request->used_at = now();
            $request->purchase_id = $purchase->id;
            $request->save();
            $this->audit->record('purchase_received', $purchase, $actor,
                newValues: $purchase->getAttributes(),
                metadata: [
                    'purchase_id' => $purchase->id,
                    'purchase_number' => $purchase->purchase_number,
                    'supplier_id' => $supplier->id,
                    'supplier_code_snapshot' => $supplier->supplier_code,
                    'item_count' => count($lines),
                    'total_amount' => $subtotal,
                ]
            );

            return $purchase;
        });
    }
}
