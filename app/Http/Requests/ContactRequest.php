<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->routeIs('customers.*') ? 'customers.manage' : 'suppliers.manage');
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:180'], 'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:1000'], 'is_active' => ['required', 'boolean']];
    }
}
