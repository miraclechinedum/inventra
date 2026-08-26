<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class SetWhatsAppConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeConsent', $this->route('customer')) ?? false;
    }

    public function rules(): array
    {
        return [
            'opt_in' => ['required', 'boolean'],
            'whatsapp_opt_in_at' => ['prohibited'],
            'whatsapp_opt_out_at' => ['prohibited'],
        ];
    }
}
