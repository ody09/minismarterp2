<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBatch extends Model
{
    protected $fillable = ['product_id', 'batch_number', 'production_date', 'expiration_date'];

    protected function casts(): array
    {
        return ['production_date' => 'date', 'expiration_date' => 'date', 'stock_started_at' => 'immutable_datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
