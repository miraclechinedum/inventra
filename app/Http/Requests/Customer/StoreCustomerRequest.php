<?php

namespace App\Http\Requests\Customer;

use App\Enums\UserRole;
use App\Models\Customer;

class StoreCustomerRequest extends CustomerProfileRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Customer::class) ?? false;
    }

    public function rules(): array
    {
        $rules = $this->profileRules(allowInitialConsent: true);

        if ($this->user()?->role === UserRole::SalesRep) {
            $rules['address'] = ['prohibited'];
            $rules['notes'] = ['prohibited'];
        }

        return $rules;
    }
}
