<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PurchaseOrder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'order_date' => 'immutable_date', 'total_amount' => 'decimal:6',
            'received_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (PurchaseOrder $order) {
            if ($order->getRawOriginal('status') !== 'draft') {
                throw new LogicException('Terminal purchase documents are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Purchase history cannot be deleted.'));
    }

    public function number(): string
    {
        return 'PO-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('line_number');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function stockOperation(): BelongsTo
    {
        return $this->belongsTo(StockOperation::class);
    }
}
