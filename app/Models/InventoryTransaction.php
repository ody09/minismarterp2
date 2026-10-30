<?php

namespace App\Models;

use App\Models\Concerns\ImmutableStockRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransaction extends Model
{
    use ImmutableStockRecord;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'input_quantity' => 'integer', 'conversion_factor' => 'integer',
            'line_number' => 'integer', 'allocation_number' => 'integer'];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(StockOperation::class, 'stock_operation_id');
    }
}
