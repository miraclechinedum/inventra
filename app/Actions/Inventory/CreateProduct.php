<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\ProductUnit;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreateProduct
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, array $data): Product
    {
        return DB::transaction(function () use ($actor, $data): Product {
            $product = new Product;
            $product->category_id = $data['category_id'];
            $product->name = $data['name'];
            $product->sku = $data['sku'];
            $product->description = $data['description'] ?? null;
            $product->cost_price = $data['cost_price'];
            $product->selling_price = $data['selling_price'];
            $product->current_stock = $data['initial_stock'];
            $product->reorder_level = $data['reorder_level'];
            $product->unit = ProductUnit::from($data['unit']);
            $product->is_active = true;
            $product->created_by = $actor->id;
            $product->save();

            $movement = new InventoryMovement;
            $movement->product_id = $product->id;
            $movement->type = InventoryMovementType::Initial;
            $movement->quantity_change = $data['initial_stock'];
            $movement->quantity_before = '0';
            $movement->quantity_after = $data['initial_stock'];
            $movement->reason = 'Initial stock';
            $movement->performed_by = $actor->id;
            $movement->save();

            $this->audit->record('product_created', $product, $actor, newValues: $product->getAttributes());

            return $product;
        });
    }
}
