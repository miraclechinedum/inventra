<?php

namespace App\Http\Requests\Settings;

use App\Models\BusinessSetting;
use App\Support\CanonicalLoginIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSettingsRequest extends FormRequest
{
    private bool $phoneWasInvalid = false;

    public function authorize(): bool
    {
        return $this->user()?->can('update', BusinessSetting::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (BusinessSetting::EDITABLE as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $trimmed = trim($value);
                $this->merge([$field => $field === 'business_name' ? $trimmed : ($trimmed === '' ? null : $trimmed)]);
            }
        }

        // The business phone is display-only contact detail, not an identity or a delivery
        // destination, so it borrows the project's Nigerian normalisation but none of the
        // Customer rules around uniqueness, consent or WhatsApp eligibility.
        $phone = $this->input('business_phone');

        if (is_string($phone) && $phone !== '') {
            $canonical = CanonicalLoginIdentifier::normalizeNigerianPhone($phone);
            $this->phoneWasInvalid = $canonical === null;
            $this->merge(['business_phone' => $canonical ?? $phone]);
        }
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'min:1', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'business_phone' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->phoneWasInvalid) {
                    $fail('Enter a valid Nigerian phone number.');
                }
            }],
            'business_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'business_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],

            // Identity, bookkeeping and fixed-by-design columns are unreachable by construction;
            // rejecting them explicitly turns a silent no-op into a visible validation error.
            'id' => ['prohibited'], 'singleton_key' => ['prohibited'],
            'created_at' => ['prohibited'], 'updated_at' => ['prohibited'], 'updated_by' => ['prohibited'],
            'currency' => ['prohibited'], 'currency_code' => ['prohibited'], 'currency_symbol' => ['prohibited'],
            'timezone' => ['prohibited'],
        ];
    }
}
