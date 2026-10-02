<?php

namespace App\Http\Requests\Customer;

use App\Support\ImageStore;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A customer photograph upload.
 *
 * The rules are ImageStore's, shared with product and staff photographs, so one upload policy
 * governs every image the application accepts. Authorization is the customer's own `update` policy:
 * whoever may edit the customer may set their photograph.
 */
class CustomerPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('customer')) ?? false;
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
