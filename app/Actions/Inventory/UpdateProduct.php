<?php

namespace App\Actions\Inventory;

use App\Enums\ProductUnit;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateProduct
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Product $product, array $data): void
    {
        DB::transaction(function () use ($actor, $data, $product): void {
            $old = $product->only(['category_id', 'name', 'sku', 'description', 'cost_price', 'selling_price', 'reorder_level', 'unit']);
            $product->category_id = $data['category_id'];
            $product->name = $data['name'];
            $product->sku = $data['sku'];
            $product->description = $data['description'] ?? null;
            $product->cost_price = $data['cost_price'];
            $product->selling_price = $data['selling_price'];
            $product->reorder_level = $data['reorder_level'];
            $product->unit = ProductUnit::from($data['unit']);
            $product->updated_by = $actor->id;
            $product->save();
            $this->audit->record('product_updated', $product, $actor, $old, $product->getAttributes());
        });
    }
}
