<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordSaleRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function rules(): array
    {
        return ['request_token' => ['required', 'string', 'size:64'], 'amount' => ['required', 'regex:/^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/', 'not_in:0,0.0,0.00'], 'payment_method' => ['required', Rule::in(['cash', 'transfer'])], 'reason' => ['required', 'string', 'max:500'], 'note' => ['nullable', 'string', 'max:500'], 'sale_return_id' => ['nullable', 'integer', Rule::exists('sale_returns', 'id')->where('sale_id', $this->route('sale')?->id)]];
    }
}
