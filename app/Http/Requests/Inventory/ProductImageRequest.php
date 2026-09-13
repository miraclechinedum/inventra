<?php

namespace App\Http\Requests\Inventory;

use App\Support\ImageStore;
use Illuminate\Foundation\Http\FormRequest;

class ProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    public function rules(): array
    {
        return ['image' => ImageStore::validationRules()];
    }

    public function messages(): array
    {
        return ImageStore::validationMessages('image');
    }
}
