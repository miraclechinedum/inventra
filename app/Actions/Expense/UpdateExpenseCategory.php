<?php

namespace App\Actions\Expense;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateExpenseCategory
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(User $actor, ExpenseCategory $category, array $data): ExpenseCategory
    {
        Gate::forUser($actor)->authorize('update', $category);

        return DB::transaction(function () use ($actor, $category, $data) {
            $locked = ExpenseCategory::query()->lockForUpdate()->findOrFail($category->id);
            $old = $locked->getAttributes();
            $locked->name = $data['name'];
            $locked->description = $data['description'] ?? null;
            if (! $locked->isDirty()) {
                return $locked;
            }
            $locked->updated_by = $actor->id;
            $locked->save();
            $this->audit->record('expense_category_updated', $locked, $actor, oldValues: $old, newValues: $locked->getAttributes());

            return $locked;
        });
    }
}
