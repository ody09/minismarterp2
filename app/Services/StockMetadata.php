<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMetadata
{
    public function updateProduct(Product $product, array $data): void
    {
        DB::transaction(function () use ($product, $data) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $product->fill($data);
            if ($product->stock_started_at && $product->isDirty('pieces_per_carton')) {
                throw ValidationException::withMessages(['pieces_per_carton' => __('inventory.packing_locked')]);
            }
            $product->save();
        }, 3);
    }

    public function updateBatch(ProductBatch $batch, array $data): void
    {
        DB::transaction(function () use ($batch, $data) {
            $batch = ProductBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $batch->fill($data);
            if ($batch->stock_started_at && $batch->isDirty(['product_id', 'batch_number', 'production_date', 'expiration_date'])) {
                throw ValidationException::withMessages(['record' => __('inventory.batch_locked')]);
            }
            $batch->save();
        }, 3);
    }
}
