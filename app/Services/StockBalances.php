<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class StockBalances
{
    public function products(array $filters = []): Builder
    {
        $warehouseId = Warehouse::where('code', 'main')->firstOrFail()->id;
        $today = CarbonImmutable::now(config('inventory.timezone'))->toDateString();

        return Product::withSum(['inventory as physical_quantity' => fn ($query) => $query->where('inventory.warehouse_id', $warehouseId)], 'quantity')
            ->withSum(['inventory as sellable_quantity' => fn ($query) => $query->where('inventory.warehouse_id', $warehouseId)->where('products.is_active', true)
                ->where(fn ($query) => $query->whereNull('product_batches.expiration_date')->orWhere('product_batches.expiration_date', '>=', $today))], 'quantity')
            ->withSum(['inventory as expired_quantity' => fn ($query) => $query->where('inventory.warehouse_id', $warehouseId)->where('product_batches.expiration_date', '<', $today)], 'quantity')
            ->when(($filters['q'] ?? '') !== '', fn ($query) => $query->where('products.name', 'like', '%'.$filters['q'].'%'));
    }
}
