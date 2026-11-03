<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CustomerProductPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'min_quantity' => 'integer', 'unit_price' => 'decimal:6',
            'is_active' => 'boolean', 'valid_from' => 'immutable_date', 'valid_to' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Deactivate price rules to retain sales references.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
