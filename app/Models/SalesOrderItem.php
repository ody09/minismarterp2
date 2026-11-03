<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SalesOrderItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'conversion_factor' => 'integer', 'unit_price' => 'decimal:6',
            'subtotal' => 'decimal:6', 'customer_price_id' => 'integer', 'price_rule_version' => 'integer'];
    }

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::{$event}(function (SalesOrderItem $item) {
                if (SalesOrder::whereKey($item->sales_order_id)->lockForUpdate()->value('status') !== 'draft') {
                    throw new LogicException('Terminal sales items are immutable.');
                }
            });
        }
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customerPrice(): BelongsTo
    {
        return $this->belongsTo(CustomerProductPrice::class, 'customer_price_id');
    }
}
