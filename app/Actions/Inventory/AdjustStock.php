<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustStock
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Product $product, array $data): InventoryMovement
    {
        return DB::transaction(function () use ($actor, $data, $product): InventoryMovement {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            if (! $locked->is_active) {
                throw ValidationException::withMessages(['product' => 'Stock cannot be adjusted for an inactive product.']);
            }

            $type = InventoryMovementType::from($data['type']);
            $this->validateSemantics($type, $data['operation'], $data['quantity']);
            $before = $locked->current_stock;
            $after = match ($data['operation']) {
                'increase' => bcadd($before, $data['quantity'], 3),
                'decrease' => bcsub($before, $data['quantity'], 3),
                'set' => bcadd($data['quantity'], '0', 3),
            };

            if (bccomp($after, '0', 3) < 0) {
                throw ValidationException::withMessages(['quantity' => 'The adjustment cannot make stock negative.']);
            }

            $change = bcsub($after, $before, 3);
            $movement = new InventoryMovement;
            $movement->product_id = $locked->id;
            $movement->type = $type;
            $movement->quantity_change = $change;
            $movement->quantity_before = $before;
            $movement->quantity_after = $after;
            $movement->reason = $data['reason'] ?? null;
            $movement->performed_by = $actor->id;
            $movement->save();

            $locked->current_stock = $after;
            $locked->updated_by = $actor->id;
            $locked->save();
            $this->audit->record('stock_adjusted', $locked, $actor, metadata: [
                'sku' => $locked->sku,
                'adjustment_type' => $type->value,
                'quantity_change' => $change,
                'quantity_before' => $before,
                'quantity_after' => $after,
            ]);

            return $movement;
        });
    }

    private function validateSemantics(InventoryMovementType $type, string $operation, string $quantity): void
    {
        $allowed = match ($type) {
            InventoryMovementType::Restock => ['increase'],
            InventoryMovementType::Damage, InventoryMovementType::Loss => ['decrease'],
            InventoryMovementType::Adjustment => ['increase', 'decrease'],
            InventoryMovementType::Correction => ['set'],
            InventoryMovementType::Initial => [],
        };

        if (! in_array($operation, $allowed, true) || ($type !== InventoryMovementType::Correction && bccomp($quantity, '0', 3) <= 0)) {
            throw ValidationException::withMessages(['operation' => 'The operation is not valid for the selected adjustment type.']);
        }
    }
}
