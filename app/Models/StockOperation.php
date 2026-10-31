<?php

namespace App\Models;

use App\Models\Concerns\ImmutableStockRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockOperation extends Model
{
    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class);
    }

    use ImmutableStockRecord;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['posted_at' => 'immutable_datetime'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
