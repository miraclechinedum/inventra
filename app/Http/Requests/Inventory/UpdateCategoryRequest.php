<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Concerns\NormalizesScalarInput;
use App\Models\ProductCategory;
use App\Tenancy\TenantRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    use NormalizesScalarInput;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('category')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeStringNormalizations(['name', 'description']);
    }

    public function rules(): array
    {
        return [
            // Ownership is the acting user's Business, never a submitted value.
            'business_id' => ['prohibited'],
            'name' => ['required', 'string', 'max:255', TenantRules::unique(ProductCategory::class, 'name')->ignore($this->route('category'))],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
