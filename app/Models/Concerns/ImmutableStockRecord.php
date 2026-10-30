<?php

namespace App\Models\Concerns;

use LogicException;

trait ImmutableStockRecord
{
    protected static function bootImmutableStockRecord(): void
    {
        static::updating(fn () => throw new LogicException('Posted stock history cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Posted stock history cannot be deleted.'));
    }
}
