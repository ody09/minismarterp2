<?php

namespace App\Http\Requests;

use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('roles.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_-]*$/', Rule::unique('roles')->ignore($this->route('role'))],
            'permissions' => ['sometimes', 'array', 'max:'.count(Permission::CODES)],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(Permission::CODES)],
        ];
    }
}
