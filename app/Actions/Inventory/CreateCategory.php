<?php

namespace App\Actions\Inventory;

use App\Models\ProductCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreateCategory
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, array $data): ProductCategory
    {
        return DB::transaction(function () use ($actor, $data): ProductCategory {
            $category = new ProductCategory;
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
