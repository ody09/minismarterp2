<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.manage');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => mb_strtolower(trim($this->email))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'is_active' => ['required', 'boolean'],
            'password' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:255', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ];
    }
}
