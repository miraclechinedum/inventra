<?php

namespace App\Http\Requests;

use App\Enums\ReturnDisposition;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\Validator;
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
        // `return_to` names where to land afterwards, and only these two values are accepted. It
        // is compared against a literal in the controller and never used as a redirect target, so
        // it cannot send anyone anywhere the application did not choose.
        return ['business_id' => ['prohibited'], 'return_to' => ['nullable', 'string', 'in:index,show'], 'request_token' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:500'], 'note' => ['nullable', 'string', 'max:500'], 'items' => ['required', 'array', 'min:1'], 'items.*' => ['array:sale_item_id,quantity,disposition'], 'items.*.sale_item_id' => ['required', 'integer', 'distinct'], 'items.*.quantity' => ['required', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'], 'items.*.disposition' => ['required', Rule::enum(ReturnDisposition::class)]];
    }

    /**
     * A rejected Return from the Sales-list panel goes back to the Sales list by name. "Back" is not
     * safe here: the panel and its live preview are fetched as plain GETs, so the session's previous
     * URL is usually that fragment or the JSON preview rather than the list the operator is on. The
     * flag tells the list to render the errors, since it has no Return form of its own to show them.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->input('return_to') === 'index') {
            $this->session()->flash('returnFailed', true);
        }

        parent::failedValidation($validator);
    }

    protected function getRedirectUrl(): string
    {
        return $this->input('return_to') === 'index'
            ? $this->redirector->getUrlGenerator()->route('sales.index')
            : parent::getRedirectUrl();
    }
}
