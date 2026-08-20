<?php

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryMovementType;
use App\Http\Requests\Concerns\NormalizesScalarInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
{
    use NormalizesScalarInput;

    public function authorize(): bool
    {
        return $this->user()?->can('adjustStock', $this->route('product')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeStringNormalizations(['type', 'operation', 'quantity', 'reason']);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([
                InventoryMovementType::Restock->value,
                InventoryMovementType::Adjustment->value,
                InventoryMovementType::Damage->value,
                InventoryMovementType::Loss->value,
                InventoryMovementType::Correction->value,
            ])],
            'operation' => ['required', Rule::in(['increase', 'decrease', 'set'])],
            'quantity' => ['required', 'decimal:0,3', 'min:0', 'max:999999999999.999'],
            'reason' => ['nullable', 'string', 'max:500'],
            'quantity_before' => ['prohibited'],
            'quantity_after' => ['prohibited'],
            'quantity_change' => ['prohibited'],
            'performed_by' => ['prohibited'],
            'reference_type' => ['prohibited'],
            'reference_id' => ['prohibited'],
        ];
    }
}
