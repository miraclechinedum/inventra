<?php

namespace App\Http\Requests\Sale;

use App\Http\Requests\Concerns\NormalizesScalarInput;
use Illuminate\Foundation\Http\FormRequest;

class DecideSaleDiscountRequest extends FormRequest
{
    use NormalizesScalarInput;

    public function authorize(): bool
    {
        // The specific approve/decline ability is checked in the controller, which knows which of
        // the two is being attempted.
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeStringNormalizations(['decision_note']);
    }

    public function rules(): array
    {
        return [
            // Ownership comes from the acting user and the parent document, never from the form.
            'business_id' => ['prohibited'],
            'decision_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
