<?php

namespace App\Http\Requests\Sale;

use App\Http\Requests\Concerns\NormalizesScalarInput;
use App\Models\SaleDiscountRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreSaleDiscountRequest extends FormRequest
{
    use NormalizesScalarInput;

    public function authorize(): bool
    {
        return $this->user()?->can('create', [SaleDiscountRequest::class, $this->route('sale')]) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeStringNormalizations(['amount', 'reason']);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            // Nobody decides their own request through the create form.
            'status' => ['prohibited'],
            'decided_by' => ['prohibited'],
            'decided_at' => ['prohibited'],
            'pending_sale_guard' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Explain the discount in at least 10 characters.',
            'amount.gt' => 'The discount must be greater than zero.',
        ];
    }
}
