<?php

namespace App\Http\Requests\Staff;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStaffRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeRole', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in([UserRole::Manager->value, UserRole::SalesRep->value])],
        ];
    }
}
