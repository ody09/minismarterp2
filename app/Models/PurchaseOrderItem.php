<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PurchaseOrderItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'conversion_factor' => 'integer', 'unit_price' => 'decimal:6', 'subtotal' => 'decimal:6'];
    }

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::{$event}(function (PurchaseOrderItem $item) {
                if (PurchaseOrder::whereKey($item->purchase_order_id)->lockForUpdate()->value('status') !== 'draft') {
                    throw new LogicException('Terminal purchase items are immutable.');
                }
            });
        }
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }
}
