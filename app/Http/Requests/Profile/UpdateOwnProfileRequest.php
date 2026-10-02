<?php

namespace App\Http\Requests\Profile;

use App\Models\User;
use App\Support\CanonicalLoginIdentifier;
use App\Support\UnavailableIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Self-service personal details: name, email and phone, for the signed-in account only.
 *
 * The subject is never named in the payload. `$this->user()` is the authenticated account, so there
 * is no id, slug or route parameter for a crafted request to point somewhere else — the endpoint is
 * structurally incapable of updating anybody but the caller. Only the three fields below are
 * validated, so `role`, `status`, `password`, `photo_path` and every other column are simply not
 * present in `validated()` and cannot reach the action.
 *
 * Normalisation matches authentication exactly, which is the point that makes this safe: login
 * resolves an identifier through CanonicalLoginIdentifier (lower-cased email, `+234…` phone), so
 * storing anything else here would leave an account that could no longer sign in with the
 * credentials its owner just chose. The same helper is used for both.
 */
class UpdateOwnProfileRequest extends FormRequest
{
    private bool $phoneWasInvalid = false;

    /**
     * Any signed-in account may edit its own details — Administrator, Manager and Sales
     * Representative alike. There is no subject to authorize against because the subject is the
     * caller; staff management stays behind UserPolicy in the Admin-only staff area.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        $name = $this->input('name');
        $email = $this->input('email');
        $phone = $this->input('phone');

        if (is_string($name)) {
            $normalized['name'] = trim($name);
        }

        // Lower-cased and trimmed, exactly as CanonicalLoginIdentifier does when resolving a login.
        if (is_string($email)) {
            $normalized['email'] = Str::lower(trim($email));
        }

        // Phone is optional in this domain, so a cleared field is a real null rather than ''. A
        // value that will not normalise is remembered and reported as such below rather than being
        // silently dropped, which would look like a successful save that changed nothing.
        if (is_string($phone)) {
            $trimmed = trim($phone);
            $canonical = $trimmed === '' ? null : CanonicalLoginIdentifier::normalizeNigerianPhone($trimmed);
            $this->phoneWasInvalid = $trimmed !== '' && $canonical === null;
            $normalized['phone'] = $canonical;
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $self = $this->user();

        return [
            // Trimmed above, so `required` already rejects a whitespace-only name. No character
            // restriction: legitimate Nigerian and international names carry spaces, hyphens,
            // apostrophes and accents, and a pattern here would reject real people.
            'name' => ['required', 'string', 'max:255'],

            // Both columns are uniquely indexed. `ignore($self)` is what lets the account
            // resubmit its own address unchanged, while still refusing another member's.
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($self)],
            'phone' => ['nullable', 'string', 'max:17', Rule::unique(User::class, 'phone')->ignore($self)],
            'business_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Full name is required.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Enter a valid email address.',
            // Identical whoever holds the identifier — a colleague or another Business.
            'email.unique' => UnavailableIdentifier::EMAIL,
            'phone.unique' => UnavailableIdentifier::PHONE,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->phoneWasInvalid) {
                $validator->errors()->add('phone', 'Enter a valid Nigerian phone number.');
            }
        });
    }
}
