<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('categories.manage');
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:180', Rule::unique('categories')->ignore($this->route('category'))],
            'description' => ['nullable', 'string', 'max:1000'], 'is_active' => ['required', 'boolean']];
    }
}
