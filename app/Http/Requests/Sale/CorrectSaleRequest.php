<?php

namespace App\Http\Requests\Sale;

use App\Http\Requests\Concerns\NormalizesScalarInput;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectSaleRequest extends FormRequest
{
    use NormalizesScalarInput;

    public function authorize(): bool
    {
        return $this->user()?->can('correct', $this->route('sale')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeStringNormalizations(['reason', 'notes']);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'customer_id' => ['nullable', 'integer', Rule::exists(Customer::class, 'id')->where('is_active', true)],

            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*.product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'products.*.quantity' => ['required', 'decimal:0,3', 'gt:0', 'max:999999999999.999'],

            // Money and stock are derived on the server from the lines above. Accepting any of
            // these from the client would be the CRUD rewrite this workflow exists to avoid.
            'subtotal' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'amount_paid' => ['prohibited'],
            'balance_due' => ['prohibited'],
            'refundable_credit' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'payment_method' => ['prohibited'],
            'status' => ['prohibited'],
            'sold_by' => ['prohibited'],
            'sale_number' => ['prohibited'],
            'created_at' => ['prohibited'],
            'unit_price' => ['prohibited'],
            'products.*.unit_price' => ['prohibited'],
            'products.*.line_total' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say what was recorded incorrectly. A correction is not accepted without a reason.',
            'reason.min' => 'Explain the correction in at least 10 characters.',
            'products.required' => 'A correction must leave at least one item on the Sale.',
            'products.*.quantity.gt' => 'Every quantity must be greater than zero. Remove a line instead of setting it to zero.',
        ];
    }
}
