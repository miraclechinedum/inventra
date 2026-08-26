<?php

namespace App\Http\Requests\Customer;

class UpdateCustomerRequest extends CustomerProfileRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('customer')) ?? false;
    }

    public function rules(): array
    {
        if ($this->user()?->can('updateIdentity', $this->route('customer'))) {
            return $this->profileRules($this->route('customer'));
        }

        return [
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'first_name' => ['prohibited'],
            'last_name' => ['prohibited'],
            'phone' => ['prohibited'],
            'address' => ['prohibited'],
            'notes' => ['prohibited'],
            'customer_code' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'is_active' => ['prohibited'],
            'whatsapp_opt_in' => ['prohibited'],
            'whatsapp_opt_in_at' => ['prohibited'],
            'whatsapp_opt_out_at' => ['prohibited'],
            'deleted_at' => ['prohibited'],
        ];
    }
}
