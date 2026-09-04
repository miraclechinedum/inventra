<?php

namespace App\Actions\Expense;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SetExpenseCategoryActive
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(User $actor, ExpenseCategory $category, bool $active): ExpenseCategory
    {
        Gate::forUser($actor)->authorize('changeStatus', $category);

        return DB::transaction(function () use ($actor, $category, $active) {
            $locked = ExpenseCategory::query()->lockForUpdate()->findOrFail($category->id);
            if ($locked->is_active === $active) {
                return $locked;
            }
            $old = $locked->getAttributes();
            $locked->is_active = $active;
            $locked->updated_by = $actor->id;
            $locked->save();
            $this->audit->record($active ? 'expense_category_activated' : 'expense_category_deactivated', $locked, $actor, oldValues: $old, newValues: $locked->getAttributes());

            return $locked;
        });
    }
}
