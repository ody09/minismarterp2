<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class DecimalAmount
{
    public const MAX_PRICE = 999999999999999;

    public const MAX_TOTAL = 999999999999999999;

    public static function price(mixed $value, string $field = 'unit_price'): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A\d{1,9}(?:\.\d{1,6})?\z/', (string) $value)) {
            throw ValidationException::withMessages([$field => __('purchases.errors.price')]);
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $value), 2, '');

        return (int) $whole * 1000000 + (int) str_pad($fraction, 6, '0');
    }

    public static function format(int $value): string
    {
        return intdiv($value, 1000000).'.'.str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function subtotal(int $price, int $quantity): int
    {
        if ($quantity < 1 || $price < 0 || ($price > 0 && $quantity > intdiv(self::MAX_TOTAL, $price))) {
            throw ValidationException::withMessages(['items' => __('purchases.errors.amount')]);
        }

        return $price * $quantity;
    }

    public static function add(int $total, int $subtotal): int
    {
        if ($subtotal < 0 || $total > self::MAX_TOTAL - $subtotal) {
            throw ValidationException::withMessages(['items' => __('purchases.errors.amount')]);
        }

        return $total + $subtotal;
    }
}
