<?php

namespace App\Http\Requests;

use App\Enums\ProductUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('products.manage');
    }

    public function rules(): array
    {
        $money = ['bail', 'required', 'numeric', 'regex:/^\d{1,9}(?:\.\d{1,6})?$/D'];

        return ['name' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['bail', 'required', 'integer', Rule::exists('categories', 'id')],
            'pieces_per_carton' => ['required', 'integer', 'min:1', 'max:1000000'],
            'purchase_unit' => ['required', Rule::enum(ProductUnit::class)], 'selling_unit' => ['required', Rule::enum(ProductUnit::class)],
            'purchase_price' => $money, 'selling_price' => $money,
            'reorder_level' => ['required', 'integer', 'min:0', 'max:2147483647'], 'is_active' => ['required', 'boolean']];
    }
}
