<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class SetProductActiveState
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Product $product, bool $active): void
    {
        DB::transaction(function () use ($active, $actor, $product): void {
            $product->is_active = $active;
            $product->updated_by = $actor->id;
            $product->save();
            $this->audit->record($active ? 'product_activated' : 'product_deactivated', $product, $actor, newValues: ['is_active' => $active]);
        });
    }
}
