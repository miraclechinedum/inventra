<?php

namespace App\Http\Requests\Sale;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
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
        foreach (['customer_id', 'payment_method', 'amount_paid', 'notes'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
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
            'customer_id' => ['required', 'integer', Rule::exists(Customer::class, 'id')->where('is_active', true)],
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*' => ['required', 'array:product_id,quantity'],
            'products.*.product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'products.*.quantity' => ['required', 'decimal:0,3', 'gt:0', 'max:999999999999.999'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount_paid' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'sale_number' => ['prohibited'],
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
