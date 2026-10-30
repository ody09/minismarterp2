<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('batches.manage');
    }

    public function rules(): array
    {
        $batch = $this->route('batch');

        return ['product_id' => ['bail', 'required', 'integer', Rule::exists('products', 'id'),
            Rule::when($batch !== null, [Rule::in([$batch?->product_id])])],
            'batch_number' => ['required', 'string', 'max:100', Rule::unique('product_batches')->where('product_id', $this->integer('product_id'))->ignore($batch)],
            'production_date' => ['nullable', 'date_format:Y-m-d'],
            'expiration_date' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('production_date'), ['after_or_equal:production_date'])]];
    }
}
