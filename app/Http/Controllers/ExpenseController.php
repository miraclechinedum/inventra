<?php

namespace App\Http\Controllers;

use App\Actions\Expense\IssueExpenseRequest;
use App\Actions\Expense\RecordExpense;
use App\Enums\PaymentMethod;
use App\Http\Requests\RecordExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Expense::class);
        $filters = collect(['search', 'category', 'payment_method', 'recorded_by', 'from', 'to'])->mapWithKeys(fn ($key) => [$key => is_string($request->query($key)) ? trim($request->query($key)) : ''])->all();
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($filters['search'], 0, 255));
        $query = Expense::query()
            ->when($escaped !== '', fn ($q) => $q->where(fn ($q) => $q->where('expense_number', 'like', mb_strtoupper($escaped).'%')->orWhere('category_name_snapshot', 'like', $escaped.'%')->orWhere('description', 'like', $escaped.'%')->orWhere('payee', 'like', $escaped.'%')->orWhere('reference_number', 'like', $escaped.'%')))
            ->when(ctype_digit($filters['category']), fn ($q) => $q->where('expense_category_id', (int) $filters['category']))
            ->when(PaymentMethod::tryFrom($filters['payment_method']), fn ($q, $method) => $q->where('payment_method', $method->value))
            ->when(ctype_digit($filters['recorded_by']), fn ($q) => $q->where('recorded_by', (int) $filters['recorded_by']))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from']), fn ($q) => $q->whereDate('incurred_at', '>=', $filters['from']))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to']), fn ($q) => $q->whereDate('incurred_at', '<=', $filters['to']));
        $total = (string) (clone $query)->sum('amount');
        $count = (clone $query)->count();
        $expenses = $query->latest('incurred_at')->latest('id')->paginate(PerPage::resolve($request))->withQueryString();

        return view('expenses.index', ['expenses' => $expenses, 'total' => $total, 'count' => $count, 'filters' => $filters, 'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name']), 'recorders' => User::whereIn('role', ['admin', 'manager'])->orderBy('name')->get(['id', 'name'])]);
    }

    public function create(Request $request, IssueExpenseRequest $tokens): View
    {
        Gate::authorize('create', Expense::class);

        return view('expenses.create', ['categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(), 'methods' => PaymentMethod::cases(), 'requestToken' => $tokens->execute($request->user(), $request->session())]);
    }

    public function store(RecordExpenseRequest $request, RecordExpense $action): RedirectResponse
    {
        $expense = $action->execute($request->user(), $request->validated(), $request->session()->getId());

        return redirect()->route('expenses.show', $expense)->with('status', 'Expense recorded.');
    }

    public function show(Expense $expense): View
    {
        Gate::authorize('view', $expense);

        return view('expenses.show', compact('expense'));
    }

    public function receipt(Expense $expense): View
    {
        Gate::authorize('view', $expense);

        return view('expenses.receipt', compact('expense'));
    }
}
