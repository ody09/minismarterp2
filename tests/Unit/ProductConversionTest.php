<?php

namespace Tests\Unit;

use App\Models\Product;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductConversionTest extends TestCase
{
    public static function validQuantities(): array
    {
        return [[3, 'carton', 72], [3, 'piece', 3], [0, 'carton', 0], [100, 'piece', 100]];
    }

    #[DataProvider('validQuantities')]
    public function test_quantities_convert_to_whole_pieces(int $quantity, string $unit, int $expected): void
    {
        $product = new Product(['pieces_per_carton' => 24]);
        $this->assertSame($expected, $product->toPieces($quantity, $unit));
    }

    public static function invalidQuantities(): array
    {
        return [[-1, 'piece', 24], [1, 'pallet', 24], [PHP_INT_MAX, 'carton', 24], [1, 'carton', 0]];
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_or_overflowing_conversions_are_rejected(int $quantity, string $unit, int $factor): void
    {
        $product = new Product(['pieces_per_carton' => $factor]);
        $this->expectException(InvalidArgumentException::class);
        $product->toPieces($quantity, $unit);
    }
}
