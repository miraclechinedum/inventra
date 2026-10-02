<?php

namespace App\Http\Requests\Inventory;

use App\Enums\ProductUnit;
use App\Http\Requests\Concerns\NormalizesScalarInput;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\ImageStore;
use App\Tenancy\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    use NormalizesScalarInput;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeStringNormalizations(
            ['name', 'sku', 'description', 'cost_price', 'selling_price', 'initial_stock', 'reorder_level', 'unit'],
            ['sku'],
        );
    }

    public function rules(): array
    {
        return [
            // Ownership is the acting user's Business, never a submitted value.
            'business_id' => ['prohibited'],
            'category_id' => ['required', 'integer', TenantRules::exists(ProductCategory::class)->where('is_active', true)],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._\/-]*$/', TenantRules::unique(Product::class, 'sku')],
            'description' => ['nullable', 'string', 'max:2000'],
            'cost_price' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'selling_price' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'initial_stock' => ['required', 'decimal:0,3', 'min:0', 'max:999999999999.999'],
            'reorder_level' => ['required', 'decimal:0,3', 'min:0', 'max:999999999999.999'],
            'unit' => ['required', Rule::enum(ProductUnit::class)],
            // Optional at creation; when present it is held to exactly the rules every other
            // image field uses, so nothing about what is accepted can drift here.
            'image' => ImageStore::validationRules(required: false),
            'current_stock' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'is_active' => ['prohibited'],
            'deleted_at' => ['prohibited'],
            'image_path' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ImageStore::validationMessages('image');
    }
}
