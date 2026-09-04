<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use App\Support\CanonicalLoginIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $supplier = $this->route('supplier');

        return $this->user()?->can($supplier instanceof Supplier ? 'update' : 'create', $supplier instanceof Supplier ? $supplier : Supplier::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'contact_person', 'phone', 'email', 'address', 'city', 'notes'] as $k) {
            if (is_string($this->input($k))) {
                $this->merge([$k => trim($this->input($k))]);
            }
        }

        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower($this->input('email'))]);
        }

        if (is_string($this->input('phone')) && $this->input('phone') !== '') {
            $phone = $this->input('phone');
            $this->merge(['phone' => CanonicalLoginIdentifier::normalizeNigerianPhone($phone)
                ?? preg_replace('/[\s\-().]/', '', $phone)]);
        }
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'contact_person' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:500'], 'city' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:1000'], 'supplier_code' => ['prohibited'], 'created_by' => ['prohibited'], 'updated_by' => ['prohibited'], 'is_active' => ['prohibited']];
    }
}
