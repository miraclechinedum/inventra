<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\NormalizesScalarInput;
use App\Models\Customer;
use App\Support\CanonicalLoginIdentifier;
use App\Tenancy\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

abstract class CustomerProfileRequest extends FormRequest
{
    use NormalizesScalarInput;

    private bool $phoneWasInvalid = false;

    private bool $whatsAppPhoneWasInvalid = false;

    protected function prepareForValidation(): void
    {
        $this->mergeStringNormalizations(
            ['first_name', 'last_name', 'phone', 'whatsapp_phone', 'email', 'address', 'city', 'notes', 'tag'],
            ['email'],
        );

        $phone = $this->input('phone');
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower($email)]);
        }

        foreach (['last_name', 'email', 'address', 'city', 'notes', 'tag'] as $optional) {
            if ($this->input($optional) === '') {
                $this->merge([$optional => null]);
            }
        }

        if (is_string($phone)) {
            $canonical = CanonicalLoginIdentifier::normalizeNigerianPhone($phone);
            $this->phoneWasInvalid = $phone !== '' && $canonical === null;
            $this->merge(['phone' => $canonical]);
        }

        // "WhatsApp same as phone number" is the absence of an alternate destination, so ticking it
        // stores null rather than a second copy of the phone. Two columns holding the same number
        // could drift apart; one column and a null cannot.
        if ($this->boolean('whatsapp_same_as_phone') || $this->input('whatsapp_phone') === '') {
            $this->merge(['whatsapp_phone' => null]);

            return;
        }

        $whatsAppPhone = $this->input('whatsapp_phone');

        if (is_string($whatsAppPhone)) {
            $canonical = CanonicalLoginIdentifier::normalizeNigerianPhone($whatsAppPhone);
            $this->whatsAppPhoneWasInvalid = $canonical === null;
            // Storing the same number in both columns is the one thing this field must not do: it
            // would mean "separate destination" and "same as phone" were indistinguishable.
            $this->merge([
                'whatsapp_phone' => $canonical === $this->input('phone') ? null : $canonical,
            ]);
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
            }, TenantRules::unique(Customer::class, 'phone')->ignore($customer)],
            // An alternate WhatsApp destination. Null — the normal case — means WhatsApp reaches
            // this customer on their ordinary phone. It is a destination, never a permission:
            // `whatsapp_opt_in` below still decides whether anything may be sent.
            'whatsapp_phone' => ['nullable', 'string', 'max:32', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->whatsAppPhoneWasInvalid) {
                    $fail('Enter a valid Nigerian WhatsApp number.');
                }
            }],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            // The short vehicle or customer descriptor the Customer screens show. Deliberately not
            // `notes`, which stays free text for anything else.
            'tag' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'customer_code' => ['prohibited'],
            'business_id' => ['prohibited'],
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
