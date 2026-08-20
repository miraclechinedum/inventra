<?php

namespace App\Http\Requests\Staff;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStaffRequest extends FormRequest
{
    private bool $phoneWasInvalid = false;

    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $email = $this->input('email');
        $phoneInput = $this->input('phone');
        $normalized = [];

        if (is_string($name)) {
            $normalized['name'] = trim($name);
        }

        if (is_string($email)) {
            $normalized['email'] = Str::lower(trim($email));
        }

        if (is_string($phoneInput)) {
            $phone = trim($phoneInput);
            $normalizedPhone = $phone === '' ? null : User::normalizePhone($phone);
            $this->phoneWasInvalid = $phone !== '' && $normalizedPhone === null;
            $normalized['phone'] = $normalizedPhone;
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'phone' => ['nullable', 'string', 'max:17', Rule::unique(User::class, 'phone')],
            'role' => ['required', Rule::in([UserRole::Manager->value, UserRole::SalesRep->value])],
        ];
    }

    public function messages(): array
    {
        return ['phone.string' => 'The phone number must be a valid Nigerian mobile number.'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->phoneWasInvalid) {
                $validator->errors()->add('phone', 'The phone number must be a valid Nigerian mobile number.');
            }
        });
    }
}
