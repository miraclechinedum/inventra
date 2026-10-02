<?php

namespace App\Http\Requests\Settings;

use App\Settings\BusinessSettings;
use App\Support\ImageStore;
use Illuminate\Foundation\Http\FormRequest;

class BusinessLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The same gate that governs every other business-settings change.
        return $this->user()?->can('update', app(BusinessSettings::class)->current()) ?? false;
    }

    public function rules(): array
    {
        // The shared image rules: real MIME, bounded size and dimensions, decodable content.
        return ['logo' => ImageStore::validationRules()];
    }

    public function messages(): array
    {
        return ImageStore::validationMessages('logo');
    }
}
