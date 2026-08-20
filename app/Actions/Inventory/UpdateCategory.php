<?php

namespace App\Actions\Inventory;

use App\Models\ProductCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateCategory
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, ProductCategory $category, array $data): void
    {
        DB::transaction(function () use ($actor, $category, $data): void {
            $old = $category->only(['name', 'description']);
            $category->name = $data['name'];
            $category->description = $data['description'] ?? null;
            $category->updated_by = $actor->id;
            $category->save();
            $this->audit->record('category_updated', $category, $actor, $old, $category->getAttributes());
        });
    }
}
