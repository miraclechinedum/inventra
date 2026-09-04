<?php

namespace App\Actions\Sale;

use App\Enums\InventoryMovementType;
use App\Enums\SaleStatus;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidSale
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Sale $sale, string $reason): Sale
    {
        return DB::transaction(function () use ($actor, $sale, $reason): Sale {
            $lockedSale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($lockedSale->status !== SaleStatus::Completed) {
                throw ValidationException::withMessages(['sale' => 'Only a completed sale can be voided.']);
            }

            if ($lockedSale->payments()->where('payment_type', 'settlement')->exists()) {
                throw ValidationException::withMessages([
                    'sale' => 'A Sale with later settlement payments requires a payment reversal or refund workflow before it can be voided.',
                ]);
            }

            $items = $lockedSale->items()->orderBy('product_id')->get();
            $productIds = $items->pluck('product_id')->unique()->sort()->values();
            $products = Product::withTrashed()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages(['sale' => 'A referenced product is unavailable for stock restoration.']);
            }

            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                $before = $product->current_stock;
                $after = bcadd($before, $item->quantity, 3);

                $movement = new InventoryMovement;
                $movement->product_id = $product->id;
                $movement->type = InventoryMovementType::SaleVoid;
                $movement->quantity_change = $item->quantity;
                $movement->quantity_before = $before;
                $movement->quantity_after = $after;
                $movement->reference_type = $lockedSale->getMorphClass();
                $movement->reference_id = $lockedSale->id;
                $movement->reason = $reason;
                $movement->performed_by = $actor->id;
                $movement->save();

                $product->current_stock = $after;
                $product->updated_by = $actor->id;
                $product->save();
            }

            $lockedSale->status = SaleStatus::Voided;
            $lockedSale->voided_by = $actor->id;
            $lockedSale->voided_at = now();
            $lockedSale->void_reason = $reason;
            $lockedSale->save();
            $this->audit->record('sale_voided', $lockedSale, $actor, newValues: $lockedSale->getAttributes());

            return $lockedSale;
        });
    }
}
