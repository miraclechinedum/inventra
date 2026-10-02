<?php

namespace App\Actions\Inventory;

use App\Models\ProductCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;

class CreateCategory
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentBusiness $currentBusiness,
    ) {}

    public function execute(User $actor, array $data): ProductCategory
    {
        $business = $this->currentBusiness->forActor($actor);

        return DB::transaction(function () use ($actor, $data, $business): ProductCategory {
            $category = new ProductCategory;
            $category->business_id = $business->getKey();
            $category->name = $data['name'];
            $category->description = $data['description'] ?? null;
            $category->is_active = true;
            $category->created_by = $actor->id;
            $category->save();
            $this->audit->record('category_created', $category, $actor, newValues: $category->getAttributes());

            return $category;
        });
    }
}
