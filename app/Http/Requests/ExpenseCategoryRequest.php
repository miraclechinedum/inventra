<?php

namespace App\Http\Requests;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;

class ExpenseCategoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function authorize(): bool
    {
        $category = $this->route('expense_category');

        return $category instanceof ExpenseCategory
            ? ($this->user()?->can('update', $category) ?? false)
            : ($this->user()?->can('create', ExpenseCategory::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'category_code' => ['prohibited'], 'is_active' => ['prohibited'],
            'created_by' => ['prohibited'], 'updated_by' => ['prohibited'],
        ];
    }
}
