<?php

namespace App\Actions\Expense;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ExpenseCategoryCode;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateExpenseCategory
{
    public function __construct(
        private AuditLogger $audit,
        private readonly CurrentBusiness $currentBusiness,
    ) {}

    public function execute(User $actor, array $data): ExpenseCategory
    {
        Gate::forUser($actor)->authorize('create', ExpenseCategory::class);
        $business = $this->currentBusiness->forActor($actor);

        return DB::transaction(function () use ($actor, $data, $business) {
            $category = new ExpenseCategory;
            $category->business_id = $business->getKey();
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
