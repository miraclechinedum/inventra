<?php

namespace App\Actions\Expense;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ExpenseCategoryCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateExpenseCategory
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(User $actor, array $data): ExpenseCategory
    {
        Gate::forUser($actor)->authorize('create', ExpenseCategory::class);

        return DB::transaction(function () use ($actor, $data) {
            $category = new ExpenseCategory;
            $category->category_code = 'PENDING-'.Str::random(20);
            $category->name = $data['name'];
            $category->description = $data['description'] ?? null;
            $category->is_active = true;
            $category->created_by = $actor->id;
            $category->save();
            $category->category_code = ExpenseCategoryCode::fromId($category->id);
            $category->save();
            $this->audit->record('expense_category_created', $category, $actor, newValues: $category->getAttributes());

            return $category;
        });
    }
}
