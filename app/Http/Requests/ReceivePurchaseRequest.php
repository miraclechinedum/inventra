<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceivePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Purchase::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['supplier_id', 'reference_number', 'note', 'request_token'] as $k) {
            if (is_string($this->input($k))) {
                $this->merge([$k => trim($this->input($k))]);
            }
        }

        $items = $this->input('items');
        if (is_array($items)) {
            foreach ($items as $i => $line) {
                if (is_array($line)) {
                    foreach (['product_id', 'quantity', 'unit_cost'] as $k) {
                        if (isset($line[$k]) && is_string($line[$k])) {
                            $items[$i][$k] = trim($line[$k]);
                        }
                    }
                }
            }

            $items = array_values(array_filter(
                $items,
                fn ($line) => ! is_array($line) || array_filter($line, fn ($value) => $value !== null && $value !== '') !== []
            ));
            $this->merge(['items' => $items]);
        }
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists(Supplier::class, 'id')],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'request_token' => ['required', 'string', 'size:64'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['array:product_id,quantity,unit_cost'],
            'items.*.product_id' => ['required', 'integer', 'distinct:strict', Rule::exists(Product::class, 'id')],
            'items.*.quantity' => ['required', 'decimal:0,3', 'gt:0', 'max:999999999999.999'],
            'items.*.unit_cost' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'purchase_number' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'received_by' => ['prohibited'],
            'received_at' => ['prohibited'],
            'supplier_name_snapshot' => ['prohibited'],
            'items.*.line_total' => ['prohibited'],
            'items.*.product_name_snapshot' => ['prohibited'],
            'items.*.current_stock' => ['prohibited'],
        ];
    }
}
