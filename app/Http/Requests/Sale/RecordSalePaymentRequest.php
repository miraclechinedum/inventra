<?php

namespace App\Http\Requests\Sale;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordSalePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recordPayment', $this->route('sale')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['amount', 'payment_method', 'note', 'request_token'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'request_token' => ['required', 'string', 'size:64'],
            'sale_id' => ['prohibited'], 'customer_id' => ['prohibited'], 'recorded_by' => ['prohibited'],
            'payment_number' => ['prohibited'], 'payment_type' => ['prohibited'], 'paid_at' => ['prohibited'],
            'cumulative_paid_after' => ['prohibited'], 'balance_after' => ['prohibited'], 'payment_status_after' => ['prohibited'],
        ];
    }
}
