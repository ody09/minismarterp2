<?php

namespace App\Models;

use App\Enums\ProductUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use InvalidArgumentException;

class Product extends Model
{
    protected $fillable = ['category_id', 'name', 'description', 'pieces_per_carton', 'purchase_unit', 'selling_unit', 'purchase_price', 'selling_price', 'reorder_level', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'stock_started_at' => 'immutable_datetime', 'pieces_per_carton' => 'integer', 'reorder_level' => 'integer',
            'purchase_price' => 'decimal:6', 'selling_price' => 'decimal:6'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function inventory(): HasManyThrough
    {
        return $this->hasManyThrough(Inventory::class, ProductBatch::class, 'product_id', 'batch_id');
    }

    public function toPieces(int $quantity, string $unit): int
    {
        $factor = match (ProductUnit::tryFrom($unit)) {
            ProductUnit::Piece => 1,
            ProductUnit::Carton => $this->pieces_per_carton,
            default => throw new InvalidArgumentException('Unknown product unit.'),
        };
        if ($quantity < 0 || $factor < 1 || $quantity > intdiv(PHP_INT_MAX, $factor)) {
            throw new InvalidArgumentException('Quantity cannot be represented as whole pieces.');
        }

        return $quantity * $factor;
    }
}
