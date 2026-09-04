<?php

namespace App\Actions\Expense;

use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ExpenseNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordExpense
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(User $actor, array $data, string $sessionId): Expense
    {
        Gate::forUser($actor)->authorize('create', Expense::class);

        return DB::transaction(function () use ($actor, $data, $sessionId) {
            $request = ExpenseRequest::query()->where('token_hash', hash('sha256', $data['request_token']))->lockForUpdate()->first();
            if (! $request || $request->actor_id !== $actor->id || ! hash_equals($request->session_id, $sessionId)) {
                throw ValidationException::withMessages(['request_token' => 'This Expense confirmation is invalid.']);
            }
            if ($request->used_at) {
                return Expense::findOrFail($request->expense_id);
            }
            if ($request->expires_at->isPast()) {
                throw ValidationException::withMessages(['request_token' => 'This Expense confirmation has expired.']);
            }

            $category = ExpenseCategory::query()->lockForUpdate()->findOrFail($data['expense_category_id']);
            if (! $category->is_active) {
                throw ValidationException::withMessages(['expense_category_id' => 'The selected Expense Category is inactive.']);
            }

            $expense = new Expense;
            $expense->expense_number = 'PENDING-'.Str::random(20);
            $expense->expense_request_id = $request->id;
            $expense->expense_category_id = $category->id;
            $expense->category_code_snapshot = $category->category_code;
            $expense->category_name_snapshot = $category->name;
            $expense->amount = bcadd($data['amount'], '0', 2);
            $expense->payment_method = PaymentMethod::from($data['payment_method']);
            $expense->payee = $data['payee'] ?? null;
            $expense->reference_number = $data['reference_number'] ?? null;
            $expense->description = $data['description'];
            $expense->note = $data['note'] ?? null;
            $expense->recorded_by = $actor->id;
            $expense->recorded_by_name_snapshot = $actor->name;
            $expense->incurred_at = $data['incurred_at'];
            $expense->save();
            $expense->expense_number = ExpenseNumber::fromId($expense->id);
            $expense->save();

            $request->used_at = now();
            $request->expense_id = $expense->id;
            $request->save();
            $this->audit->record('expense_recorded', $expense, $actor, newValues: $expense->getAttributes(), metadata: [
                'expense_id' => $expense->id,
                'expense_number' => $expense->expense_number,
                'expense_category_id' => $category->id,
                'category_code_snapshot' => $category->category_code,
                'amount' => $expense->amount,
                'payment_method' => $expense->payment_method->value,
                'incurred_at' => $expense->incurred_at->toDateString(),
            ]);

            return $expense;
        });
    }
}
