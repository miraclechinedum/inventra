<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\UnavailableIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Public signup: who the owner is and what their Business is called — nothing more.
 *
 * Email and phone are login identifiers, unique across every Business, and are canonicalised
 * before they are checked. An unavailable one is refused with the same wording staff forms use,
 * which never says an account exists. Role, status, Business and every other server-assigned field
 * are refused outright rather than ignored.
 */
class RegisterBusinessRequest extends FormRequest
{
    private bool $phoneWasInvalid = false;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['owner_name', 'business_name'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }

        if (is_string($this->input('email'))) {
            $normalized['email'] = Str::lower(trim($this->input('email')));
        }

        if (is_string($this->input('phone'))) {
            $phone = trim($this->input('phone'));
            $canonical = $phone === '' ? null : User::normalizePhone($phone);
            $this->phoneWasInvalid = $phone !== '' && $canonical === null;
            $normalized['phone'] = $canonical;
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'phone' => ['required', 'string', 'max:17', Rule::unique(User::class, 'phone')],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'business_name' => ['required', 'string', 'max:150'],
            // Assigned by the server alone.
            'business_id' => ['prohibited'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
            'plan' => ['prohibited'],
            'plan_id' => ['prohibited'],
            'subscription_status' => ['prohibited'],
            'trial_ends_at' => ['prohibited'],
            'business_status' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'owner_name.required' => 'Enter your full name.',
            'business_name.required' => 'Enter your business name.',
            'phone.required' => 'Enter a Nigerian mobile number.',
            'email.unique' => UnavailableIdentifier::EMAIL,
            'phone.unique' => UnavailableIdentifier::PHONE,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->phoneWasInvalid) {
                $validator->errors()->add('phone', 'The phone number must be a valid Nigerian mobile number.');
            }
        });
    }

    /** @return array{business_name: string, owner_name: string, email: string, phone: string|null, password: string} */
    public function provisioning(): array
    {
        $validated = $this->validated();

        return [
            'business_name' => $validated['business_name'],
            'owner_name' => $validated['owner_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => $validated['password'],
        ];
    }
}
