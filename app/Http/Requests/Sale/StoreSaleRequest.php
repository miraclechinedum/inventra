<?php

namespace App\Http\Requests\Sale;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDraft;
use App\Tenancy\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Sale::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // The Record Sale screen no longer asks how the money arrived, so a sale that does not say
        // is taken as cash — the counter default for this business. `payment_method` stays required
        // below rather than becoming nullable: the column is NOT NULL, every receipt and report
        // reads it, and it is copied onto each sale_payments row, so a missing value would be a
        // hole in the ledger rather than an empty field. Any caller that does state a method still
        // has it validated against the enum exactly as before.
        if (! $this->filled('payment_method')) {
            $this->merge(['payment_method' => PaymentMethod::Cash->value]);
        }

        foreach (['customer_id', 'payment_method', 'amount_paid', 'notes', 'sale_date'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }

        // Money arrives from a text field, so a human may reasonably have typed "12,500.00" or
        // "₦12,500". Thousands separators and the currency symbol are removed before validation
        // rather than being rejected — they are formatting, not a different amount.
        //
        // Nothing else is forgiven: a decimal comma, a negative, a third decimal place or any other
        // text still fails `decimal:0,2` below, and the amount is never parsed as a float. This is
        // normalisation of presentation, not a relaxation of what counts as valid money.
        if (is_string($amount = $this->input('amount_paid'))) {
            $this->merge(['amount_paid' => preg_replace('/(?<=\d),(?=\d{3}\b)|[₦\s]/u', '', $amount)]);
        }

        $products = $this->input('products');

        if (is_array($products)) {
            foreach ($products as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                foreach (['product_id', 'quantity'] as $key) {
                    if (isset($line[$key]) && is_string($line[$key])) {
                        $products[$index][$key] = trim($line[$key]);
                    }
                }
            }

            $products = array_values(array_filter($products, fn ($line): bool => ! is_array($line)
                || ($line['product_id'] ?? '') !== ''
                || ($line['quantity'] ?? '') !== ''));
            $this->merge(['products' => $products]);
        }
    }

    public function rules(): array
    {
        return [
            // Ownership comes from the acting user and the parent document, never from the form.
            'business_id' => ['prohibited'],
            // A Sale names a registered customer or is explicitly a walk-in — never both, never
            // neither. The database CHECK enforces the same shape; this states it for the user.
            'is_walk_in' => ['required', 'boolean'],
            'customer_id' => [
                Rule::requiredIf(fn (): bool => ! $this->boolean('is_walk_in')),
                'nullable',
                'prohibited_if:is_walk_in,1',
                'integer',
                TenantRules::exists(Customer::class)->where('is_active', true),
            ],
            // The day the trade happened. Backdating is legitimate; postdating is not.
            'sale_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            // A pre-approved discount, identified by its draft. Ownership, approval status and the
            // cart fingerprint are all re-checked server-side in CreateSale.
            'sale_draft_id' => ['nullable', 'integer', TenantRules::exists(SaleDraft::class)],
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*' => ['required', 'array:product_id,quantity'],
            'products.*.product_id' => ['required', 'integer', TenantRules::exists(Product::class)],
            'products.*.quantity' => ['required', 'decimal:0,3', 'gt:0', 'max:999999999999.999'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount_paid' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'sale_number' => ['prohibited'],
            'is_walk_in_snapshot' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'balance_due' => ['prohibited'],
            'status' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'sold_by' => ['prohibited'],
            'sold_by_name_snapshot' => ['prohibited'],
            'voided_by' => ['prohibited'],
            'customer_code_snapshot' => ['prohibited'],
            'customer_name_snapshot' => ['prohibited'],
            'customer_phone_snapshot' => ['prohibited'],
            'products.*.unit_price' => ['prohibited'],
            'products.*.line_total' => ['prohibited'],
            'products.*.product_sku_snapshot' => ['prohibited'],
            'products.*.product_name_snapshot' => ['prohibited'],
            'products.*.quantity_before' => ['prohibited'],
            'products.*.quantity_after' => ['prohibited'],
        ];
    }
}
