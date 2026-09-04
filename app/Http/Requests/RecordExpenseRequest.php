<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordExpenseRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['expense_category_id', 'amount', 'payment_method', 'payee', 'reference_number', 'description', 'note', 'incurred_at', 'request_token'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Expense::class) ?? false;
    }

    public function rules(): array
    {
        $businessDate = now(config('business.timezone'))->toDateString();

        return [
            'expense_category_id' => ['required', 'integer', Rule::exists(ExpenseCategory::class, 'id')],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payee' => ['nullable', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
            'incurred_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$businessDate],
            'request_token' => ['required', 'string', 'size:64'],
            'expense_number' => ['prohibited'], 'category_code_snapshot' => ['prohibited'],
            'category_name_snapshot' => ['prohibited'], 'recorded_by' => ['prohibited'],
            'recorded_by_name_snapshot' => ['prohibited'], 'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'], 'token_hash' => ['prohibited'], 'expense_id' => ['prohibited'],
        ];
    }
}
