<?php

namespace App\Http\Controllers;

use App\Actions\Expense\CreateExpenseCategory;
use App\Actions\Expense\SetExpenseCategoryActive;
use App\Actions\Expense\UpdateExpenseCategory;
use App\Http\Requests\ExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ExpenseCategory::class);
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 255));
        $status = is_string($request->query('status')) ? $request->query('status') : '';
        $categories = ExpenseCategory::query()->withCount('expenses')->withMax('expenses', 'incurred_at')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('category_code', 'like', mb_strtoupper($escaped).'%')->orWhere('name', 'like', $escaped.'%')->orWhere('description', 'like', $escaped.'%')))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('expense-categories.index', compact('categories', 'search', 'status'));
    }

    public function create(): View
    {
        Gate::authorize('create', ExpenseCategory::class);

        return view('expense-categories.create');
    }

    public function store(ExpenseCategoryRequest $request, CreateExpenseCategory $action): RedirectResponse
    {
        $category = $action->execute($request->user(), $request->validated());

        return redirect()->route('expense-categories.show', $category)->with('status', 'Expense Category created.');
    }

    public function show(ExpenseCategory $expenseCategory): View
    {
        Gate::authorize('view', $expenseCategory);

        return view('expense-categories.show', ['category' => $expenseCategory->loadCount('expenses'), 'expenses' => $expenseCategory->expenses()->latest('incurred_at')->paginate(10)]);
    }

    public function edit(ExpenseCategory $expenseCategory): View
    {
        Gate::authorize('update', $expenseCategory);

        return view('expense-categories.edit', ['category' => $expenseCategory]);
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $expenseCategory, UpdateExpenseCategory $action): RedirectResponse
    {
        $category = $action->execute($request->user(), $expenseCategory, $request->validated());

        return redirect()->route('expense-categories.show', $category)->with('status', 'Expense Category updated.');
    }

    public function activate(Request $request, ExpenseCategory $expenseCategory, SetExpenseCategoryActive $action): RedirectResponse
    {
        $action->execute($request->user(), $expenseCategory, true);

        return back()->with('status', 'Expense Category activated.');
    }

    public function deactivate(Request $request, ExpenseCategory $expenseCategory, SetExpenseCategoryActive $action): RedirectResponse
    {
        $action->execute($request->user(), $expenseCategory, false);

        return back()->with('status', 'Expense Category deactivated.');
    }
}
