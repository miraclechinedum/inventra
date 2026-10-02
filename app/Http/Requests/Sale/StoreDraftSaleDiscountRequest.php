<?php

namespace App\Http\Requests\Sale;

use App\Http\Requests\Concerns\NormalizesScalarInput;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleDiscountRequest;
use App\Tenancy\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asking for a discount on a cart that has not been sold yet.
 *
 * The payload mirrors the sale it will become — buyer, trading day and lines — because all three
 * are bound into the approval's fingerprint. Everything financial is still derived server-side:
 * the subtotal is computed from current catalogue prices, and only `amount` and `reason` are the
 * requester's to state.
 */
class StoreDraftSaleDiscountRequest extends FormRequest
{
    use NormalizesScalarInput;

    public function authorize(): bool
    {
        return $this->user()?->can('createForDraft', SaleDiscountRequest::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeStringNormalizations(['amount', 'reason', 'sale_date', 'customer_id']);
    }

    public function rules(): array
    {
        return [
            // Ownership comes from the acting user and the parent document, never from the form.
            'business_id' => ['prohibited'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],

            // The same buyer/date shape StoreSaleRequest enforces, so an approval can only ever be
            // granted for a sale the system would actually accept.
            'is_walk_in' => ['required', 'boolean'],
            'customer_id' => [
                Rule::requiredIf(fn (): bool => ! $this->boolean('is_walk_in')),
                'nullable',
                'prohibited_if:is_walk_in,1',
                'integer',
                TenantRules::exists(Customer::class)->where('is_active', true),
            ],
            'sale_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],

            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*' => ['required', 'array:product_id,quantity'],
            'products.*.product_id' => ['required', 'integer', TenantRules::exists(Product::class)],
            'products.*.quantity' => ['required', 'decimal:0,3', 'gt:0', 'max:999999999999.999'],

            // The requester never states the outcome, the subject, or the money that follows.
            'status' => ['prohibited'],
            'decided_by' => ['prohibited'],
            'decided_at' => ['prohibited'],
            'sale_id' => ['prohibited'],
            'sale_draft_id' => ['prohibited'],
            'cart_hash' => ['prohibited'],
            'subtotal_snapshot' => ['prohibited'],
            'pending_sale_guard' => ['prohibited'],
            'pending_draft_guard' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Explain the discount in at least 10 characters.',
            'amount.gt' => 'The discount must be greater than zero.',
            'sale_date.before_or_equal' => "Sale date can't be in the future.",
        ];
    }
}
