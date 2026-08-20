<?php

namespace App\Actions\Inventory;

use App\Models\ProductCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetCategoryActiveState
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, ProductCategory $category, bool $active): void
    {
        DB::transaction(function () use ($active, $actor, $category): void {
            if (! $active && $category->products()->whereNull('deleted_at')->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['category' => 'Deactivate dependent products before deactivating this category.']);
            }

            $category->is_active = $active;
            $category->updated_by = $actor->id;
            $category->save();
            $this->audit->record($active ? 'category_activated' : 'category_deactivated', $category, $actor, newValues: ['is_active' => $active]);
        });
    }
}
