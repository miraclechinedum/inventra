<?php

namespace App\Http\Requests;

use App\Enums\ReturnDisposition;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function rules(): array
    {
        return ['request_token' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:500'], 'note' => ['nullable', 'string', 'max:500'], 'items' => ['required', 'array', 'min:1'], 'items.*' => ['array:sale_item_id,quantity,disposition'], 'items.*.sale_item_id' => ['required', 'integer', 'distinct'], 'items.*.quantity' => ['required', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'], 'items.*.disposition' => ['required', Rule::enum(ReturnDisposition::class)]];
    }
}
