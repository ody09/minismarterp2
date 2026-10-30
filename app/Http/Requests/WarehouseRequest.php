<?php

namespace App\Http\Requests;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('warehouses.manage');
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:180', Rule::unique('warehouses')->ignore(Warehouse::where('code', 'main')->value('id'))],
            'location' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:1000']];
    }
}
