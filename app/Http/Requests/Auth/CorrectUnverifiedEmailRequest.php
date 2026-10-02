<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\UnavailableIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Correcting a mistyped address before it is verified: the new address and the account's current
 * password, nothing else.
 *
 * Only an account that still owes verification may use it — a verified account changes its email
 * through its profile. The address follows the same global identity rules as signup, and an
 * unavailable one is refused in the same neutral words, whoever holds it.
 */
class CorrectUnverifiedEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->owesEmailVerification() === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($this->user())],
            'current_password' => ['required', 'string', 'current_password'],
            // The account is the signed-in one; nothing here can name another or its state.
            'user_id' => ['prohibited'],
            'business_id' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
            'email_verification_required' => ['prohibited'],
            'verification_required' => ['prohibited'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => UnavailableIdentifier::EMAIL,
            'current_password.current_password' => 'That password is not correct.',
            'current_password.required' => 'Enter your current password.',
        ];
    }
}
