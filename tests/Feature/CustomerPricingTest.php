<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\CustomerPricing;
use App\Services\StockContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesData;
use Tests\TestCase;

class CustomerPricingTest extends TestCase
{
    use BuildsSalesData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesData();
    }

    private function resolve(int $quantity, string $unit = 'piece', string $date = '2026-11-03'): array
    {
        return DB::transaction(function () use ($quantity, $unit, $date) {
            app(StockContext::class)->lock($this->actor, ['sales.manage']);

            return app(CustomerPricing::class)->resolve($this->customer, $this->product->fresh(), $quantity, $unit, $date);
        });
    }

    public function test_highest_eligible_quantity_tier_wins_and_zero_price_is_valid(): void
    {
        $pricing = app(CustomerPricing::class);
        $base = $pricing->save($this->actor, $this->priceData());
        $tier = $pricing->save($this->actor, $this->priceData(['min_quantity' => 10, 'unit_price' => '0']));
        $this->assertSame(1250001, $this->resolve(9)['price']);
        $this->assertSame($base->id, $this->resolve(9)['rule_id']);
        $this->assertSame(0, $this->resolve(10)['price']);
        $this->assertSame($tier->id, $this->resolve(10)['rule_id']);
        $this->assertSame('customer', $this->resolve(10)['source']);
    }

    public function test_price_dates_are_inclusive_and_units_customers_and_inactive_rules_are_scoped(): void
    {
        $pricing = app(CustomerPricing::class);
        $price = $pricing->save($this->actor, $this->priceData(['valid_from' => '2026-11-03', 'valid_to' => '2026-11-05']));
        $this->assertSame('catalog', $this->resolve(5, date: '2026-11-02')['source']);
        $this->assertSame('customer', $this->resolve(5)['source']);
        $this->assertSame('customer', $this->resolve(5, date: '2026-11-05')['source']);
        $this->assertSame('catalog', $this->resolve(5, date: '2026-11-06')['source']);
        $this->assertSame(60000024, $this->resolve(1, 'carton')['price']);
        $price = $pricing->save($this->actor, $this->priceData(['version' => 1, 'is_active' => false]), $price);
        $this->assertSame('catalog', $this->resolve(5)['source']);
        $other = $this->customer->replicate();
        $other->save();
        $pricing->save($this->actor, $this->priceData(['customer_id' => $other->id]));
        $this->assertSame('catalog', $this->resolve(5)['source']);
        $this->assertSame(2, $price->version);
    }

    public function test_same_tier_overlapping_periods_are_rejected_but_adjacent_dates_and_other_tiers_work(): void
    {
        $pricing = app(CustomerPricing::class);
        $pricing->save($this->actor, $this->priceData(['valid_to' => '2026-11-03']));
        $this->assertSaleInvalid(fn () => $pricing->save($this->actor, $this->priceData(['valid_from' => '2026-11-03'])));
        $pricing->save($this->actor, $this->priceData(['valid_from' => '2026-11-04']));
        $pricing->save($this->actor, $this->priceData(['min_quantity' => 10]));
        $this->assertDatabaseCount('customer_product_prices', 3);
    }

    public function test_open_dates_and_stale_price_edits_have_defined_behavior(): void
    {
        $pricing = app(CustomerPricing::class);
        $price = $pricing->save($this->actor, $this->priceData(['valid_from' => '2026-11-01']));
        $this->assertSame('customer', $this->resolve(1, date: '2099-01-01')['source']);
        $pricing->save($this->actor, $this->priceData(['version' => 1, 'unit_price' => '3.000001']), $price);
        $this->assertSaleInvalid(fn () => $pricing->save($this->actor, $this->priceData(['version' => 1]), $price));
        $this->assertSame('3.000001', $price->fresh()->unit_price);
    }

    public function test_catalog_unit_conversion_uses_six_decimal_half_up_without_floats(): void
    {
        $this->product->update(['selling_unit' => 'carton', 'selling_price' => '1', 'pieces_per_carton' => 3]);
        $this->assertSame(333333, $this->resolve(1)['price']);
        $this->product->update(['selling_price' => '0.000001', 'pieces_per_carton' => 2]);
        $this->assertSame(1, $this->resolve(1)['price']);
        $this->product->update(['selling_unit' => 'piece', 'selling_price' => '999999999.999999', 'pieces_per_carton' => 24]);
        $this->assertSaleInvalid(fn () => $this->resolve(1, 'carton'));
    }

    public function test_bad_price_dates_and_inactive_master_data_are_rejected(): void
    {
        $pricing = app(CustomerPricing::class);
        foreach (['-1', '1e2', '0.0000001', '1000000000', ['bad'], 1.5] as $value) {
            $this->assertSaleInvalid(fn () => $pricing->save($this->actor, $this->priceData(['unit_price' => $value])));
        }
        $this->assertSaleInvalid(fn () => $pricing->save($this->actor, $this->priceData(['valid_from' => '2026-12-01', 'valid_to' => '2026-11-01'])));
        $this->assertSaleInvalid(fn () => $pricing->save($this->actor, $this->priceData(['min_quantity' => 0])));
        $this->customer->update(['is_active' => false]);
        $this->assertSaleInvalid(fn () => $pricing->save($this->actor, $this->priceData()));
        $this->assertDatabaseCount('customer_product_prices', 0);
    }

    public function test_price_service_requires_current_manage_permission(): void
    {
        $role = Role::where('code', 'sales')->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id])->refresh();
        $this->expectException(AuthorizationException::class);
        app(CustomerPricing::class)->save($user, $this->priceData());
    }
}
