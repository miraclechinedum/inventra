<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use App\Subscriptions\Entitlement;
use App\Subscriptions\Entitlements;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;

class SetProductActiveState
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Entitlements $entitlements,
        private readonly CurrentBusiness $tenancy,
    ) {}

    public function execute(User $actor, Product $product, bool $active): void
    {
        DB::transaction(function () use ($active, $actor, $product): void {
            // Back in service means back in the plan's product allowance.
            if ($active && ! $product->is_active) {
                $this->entitlements->claim($this->tenancy->forActor($actor), Entitlement::MaxProducts);
            }

            $product->is_active = $active;
            $product->updated_by = $actor->id;
            $product->save();
            $this->audit->record($active ? 'product_activated' : 'product_deactivated', $product, $actor, newValues: ['is_active' => $active]);
        });
    }
}
