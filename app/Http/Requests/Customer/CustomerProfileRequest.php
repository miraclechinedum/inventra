<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\NormalizesScalarInput;
use App\Models\Customer;
use App\Support\CanonicalLoginIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

abstract class CustomerProfileRequest extends FormRequest
{
    use NormalizesScalarInput;

    private bool $phoneWasInvalid = false;

    protected function prepareForValidation(): void
    {
        $this->mergeStringNormalizations(
            ['first_name', 'last_name', 'phone', 'email', 'address', 'city', 'notes'],
            ['email'],
        );

        $phone = $this->input('phone');
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower($email)]);
        }

        foreach (['last_name', 'email', 'address', 'city', 'notes'] as $optional) {
            if ($this->input($optional) === '') {
                $this->merge([$optional => null]);
            }
        }

        if (is_string($phone)) {
            $canonical = CanonicalLoginIdentifier::normalizeNigerianPhone($phone);
            $this->phoneWasInvalid = $phone !== '' && $canonical === null;
            $this->merge(['phone' => $canonical]);
        }
    }

    protected function profileRules(?Customer $customer = null, bool $allowInitialConsent = false): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:14', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->phoneWasInvalid) {
                    $fail('Enter a valid Nigerian phone number.');
                }
            }, Rule::unique(Customer::class, 'phone')->ignore($customer)],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'customer_code' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'is_active' => ['prohibited'],
            'whatsapp_opt_in' => $allowInitialConsent ? ['sometimes', 'boolean'] : ['prohibited'],
            'whatsapp_opt_in_at' => ['prohibited'],
            'whatsapp_opt_out_at' => ['prohibited'],
            'deleted_at' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['phone.unique' => 'A customer with this phone number already exists.'];
    }
}
