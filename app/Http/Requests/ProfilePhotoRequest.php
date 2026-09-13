<?php

namespace App\Http\Requests;

use App\Support\ImageStore;
use Illuminate\Foundation\Http\FormRequest;

class ProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('updatePhoto', $user);
    }

    public function rules(): array
    {
        return ['photo' => ImageStore::validationRules()];
    }

    public function messages(): array
    {
        return ImageStore::validationMessages('photo');
    }
}
