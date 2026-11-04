<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SalesOrder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'order_date' => 'immutable_date', 'total_amount' => 'decimal:6',
            'confirmed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (SalesOrder $order) {
            if ($order->getRawOriginal('status') !== 'draft') {
                throw new LogicException('Terminal sales documents are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Sales history cannot be deleted.'));
    }

    public function number(): string
    {
        return 'SO-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class)->orderBy('line_number');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
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
