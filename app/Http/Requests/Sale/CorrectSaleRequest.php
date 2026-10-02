<?php

namespace App\Http\Requests\Sale;

use App\Http\Requests\Concerns\NormalizesScalarInput;
use App\Models\Customer;
use App\Models\Product;
use App\Tenancy\TenantRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CorrectSaleRequest extends FormRequest
{
    use NormalizesScalarInput;

    public function authorize(): bool
    {
        return $this->user()?->can('correct', $this->route('sale')) ?? false;
    }

    /**
     * A rejected correction returns to wherever it was started from — Laravel redirects back, which
     * for the side panel is the Sales list and for the full page is the form. Old input comes with
     * it either way, so nothing the operator typed is lost.
     *
     * The extra flash names the Sale so the list can reopen its panel on the right row. It is a
     * public identifier, not a URL, so it cannot redirect anywhere the application did not choose.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->input('return_to') === 'index') {
            $this->session()->flash('correctionFailedFor', $this->route('sale')?->public_id);
        }

        parent::failedValidation($validator);
    }

    protected function prepareForValidation(): void
    {
        $this->mergeStringNormalizations(['reason', 'notes']);
    }

    public function rules(): array
    {
        return [
            // Ownership comes from the acting user and the parent document, never from the form.
            'business_id' => ['prohibited'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'customer_id' => ['nullable', 'integer', TenantRules::exists(Customer::class)->where('is_active', true)],

            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*.product_id' => ['required', 'integer', TenantRules::exists(Product::class)],
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
