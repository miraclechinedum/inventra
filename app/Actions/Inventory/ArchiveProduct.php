<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ArchiveProduct
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Product $product): void
    {
        DB::transaction(function () use ($actor, $product): void {
            $product->is_active = false;
            $product->updated_by = $actor->id;
            $product->save();
            $this->audit->record('product_archived', $product, $actor, newValues: ['is_active' => false]);
            $product->delete();
        });
    }
}
