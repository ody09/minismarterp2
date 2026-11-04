<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Permission;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\CustomerPricing;
use App\Services\SalesOrders;
use App\Services\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\BuildsSalesData;
use Tests\TestCase;

class SalesOrdersTest extends TestCase
{
    use BuildsSalesData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesData();
    }

    private function stock(int $quantity, ?string $expiry = null): ProductBatch
    {
        $batch = ProductBatch::create(['product_id' => $this->product->id, 'batch_number' => (string) Str::uuid(), 'expiration_date' => $expiry]);
        app(StockLedger::class)->receive($this->actor, (string) Str::uuid(), 'TEST:'.Str::uuid(),
            [['batch_id' => $batch->id, 'quantity' => $quantity, 'unit' => 'piece']]);

        return $batch;
    }

    private function sale(array $changes = []): SalesOrder
    {
        return app(SalesOrders::class)->save($this->actor, $this->saleData($changes));
    }

    private function confirmation(SalesOrder $order, array $changes = []): array
    {
        return array_replace(['key' => (string) Str::uuid(), 'version' => $order->version], $changes);
    }

    public function test_sales_prices_and_totals_are_server_derived_with_auditable_snapshots(): void
    {
        $rule = app(CustomerPricing::class)->save($this->actor, $this->priceData(['min_quantity' => 10]));
        $order = $this->sale(['total_amount' => '0', 'status' => 'confirmed', 'items' => [
            ['product_id' => $this->product->id, 'quantity' => 10, 'unit' => 'piece', 'unit_price' => '0'],
            ['product_id' => $this->product->id, 'quantity' => 2, 'unit' => 'carton', 'subtotal' => '0']]]);
        $this->assertSame('draft', $order->status);
        $this->assertSame('132.500058', $order->total_amount);
        $this->assertSame(['12.500010', '120.000048'], $order->items->pluck('subtotal')->all());
        $this->assertSame(['customer', 'catalog'], $order->items->pluck('price_source')->all());
        $this->assertSame($rule->id, $order->items->first()->customer_price_id);
        $this->assertSame(1, $order->items->first()->price_rule_version);
        $this->assertSame([1, 24], $order->items->pluck('conversion_factor')->all());
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_duplicate_create_and_stale_draft_edits_do_not_duplicate_or_erase_orders(): void
    {
        $service = app(SalesOrders::class);
        $data = $this->saleData();
        $one = $service->save($this->actor, $data);
        $this->assertSame($one->id, $service->save($this->actor, $data)->id);
        $this->assertSaleInvalid(fn () => $service->save($this->actor, [...$data, 'notes' => 'Changed']));
        $updated = $service->save($this->actor, [...$data, 'version' => 1, 'notes' => '0'], $one);
        $this->assertSame(2, $updated->version);
        $this->assertSame('0', $updated->notes);
        $this->assertSaleInvalid(fn () => $service->save($this->actor, [...$data, 'version' => 1], $one));
        $this->assertDatabaseCount('sales_orders', 1);
    }

    public function test_confirm_performs_fefo_splits_excludes_expired_stock_and_replays_once(): void
    {
        $early = $this->stock(10, '2026-11-04');
        $later = $this->stock(30, '2026-11-10');
        CarbonImmutable::setTestNow('2026-11-01 09:00:00 UTC');
        $expired = $this->stock(10, '2026-11-02');
        CarbonImmutable::setTestNow('2026-11-03 09:00:00 UTC');
        $order = $this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 25, 'unit' => 'piece']]]);
        $data = $this->confirmation($order);
        $posted = app(SalesOrders::class)->confirm($this->actor, $order, $data);
        $replay = app(SalesOrders::class)->confirm($this->actor, $order, $data);
        $this->assertSame('confirmed', $posted->status);
        $this->assertSame($posted->stock_operation_id, $replay->stock_operation_id);
        $this->assertSame($this->actor->id, $posted->confirmed_by);
        $this->assertSame($order->id, $posted->stockOperation->salesOrder->id);
        $moves = $posted->stockOperation->transactions()->with('inventory')->orderBy('allocation_number')->get();
        $this->assertSame([-10, -15], $moves->pluck('quantity')->all());
        $this->assertSame([$early->id, $later->id], $moves->map(fn ($move) => $move->inventory->batch_id)->all());
        $this->assertSame([1, 1], $moves->pluck('line_number')->all());
        $this->assertSame(10, Inventory::where('batch_id', $expired->id)->firstOrFail()->quantity);
        $this->assertSame(25, (int) Inventory::sum('quantity'));
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->confirm($this->actor, $order, $this->confirmation($order)));
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->confirm($this->actor, $order, [...$data, 'version' => 2]));
        $this->artisan('erp:check-stock')->assertSuccessful();
    }

    public function test_insufficient_stock_and_failure_on_second_allocation_leave_entire_sale_unposted(): void
    {
        $this->stock(6);
        $this->stock(6);
        $order = $this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 13, 'unit' => 'piece']]]);
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->confirm($this->actor, $order, $this->confirmation($order)));
        $other = $this->sale();
        $count = 0;
        Event::listen('eloquent.creating: '.InventoryTransaction::class, function () use (&$count) {
            if (++$count === 2) {
                throw new \RuntimeException('Failed second sale allocation');
            }
        });
        try {
            app(SalesOrders::class)->confirm($this->actor, $other, $this->confirmation($other));
            $this->fail('Expected allocation failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Failed second sale allocation', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.InventoryTransaction::class);
        }
        foreach ([$order, $other] as $sale) {
            $this->assertSame('draft', $sale->fresh()->status);
            $this->assertNull($sale->fresh()->stock_operation_id);
        }
        $this->assertSame(12, (int) Inventory::sum('quantity'));
        $this->assertDatabaseCount('stock_operations', 2);
        $this->assertDatabaseCount('inventory_transactions', 2);
    }

    public function test_changed_pricing_requires_explicit_draft_review_and_save_before_confirmation(): void
    {
        $this->stock(20);
        $order = $this->sale();
        $this->product->update(['selling_price' => '3.000001']);
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->confirm($this->actor, $order, $this->confirmation($order)));
        $this->assertSame('25.000010', $order->fresh()->total_amount);
        $rule = app(CustomerPricing::class)->save($this->actor, $this->priceData());
        $updated = app(SalesOrders::class)->save($this->actor, $this->saleData(['version' => 1]), $order);
        $this->assertSame('12.500010', $updated->total_amount);
        app(CustomerPricing::class)->save($this->actor, $this->priceData(['version' => 1]), $rule);
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->confirm($this->actor, $updated, $this->confirmation($updated)));
        $updated = app(SalesOrders::class)->save($this->actor, $this->saleData(['version' => 2]), $updated);
        $posted = app(SalesOrders::class)->confirm($this->actor, $updated, $this->confirmation($updated));
        $this->assertSame('confirmed', $posted->status);
        $this->assertSame('12.500010', $posted->total_amount);
    }

    public function test_changed_packing_and_inactive_customer_or_product_reject_confirmation(): void
    {
        $order = $this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit' => 'carton']]]);
        $this->product->update(['pieces_per_carton' => 12]);
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->confirm($this->actor, $order, $this->confirmation($order)));
        $this->product->update(['pieces_per_carton' => 24]);
        $this->stock(30);
        $this->customer->update(['is_active' => false]);
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->confirm($this->actor, $order, $this->confirmation($order)));
        $this->customer->update(['is_active' => true]);
        $this->product->update(['is_active' => false]);
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->confirm($this->actor, $order, $this->confirmation($order)));
        $this->assertSame(30, (int) Inventory::sum('quantity'));
    }

    public function test_cancellation_stale_forms_and_terminal_history_are_protected(): void
    {
        $this->stock(20);
        $order = $this->sale();
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->cancel($this->actor, $order, 2));
        $cancelled = app(SalesOrders::class)->cancel($this->actor, $order, 1);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(1, $cancelled->items()->count());
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->confirm($this->actor, $cancelled, $this->confirmation($cancelled)));
        $posted = $this->sale();
        $posted = app(SalesOrders::class)->confirm($this->actor, $posted, $this->confirmation($posted));
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->cancel($this->actor, $posted, 2));
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->save($this->actor, $this->saleData(['version' => 2]), $posted));
        $this->assertSaleInvalid(fn () => app(StockLedger::class)->reverse($this->actor, (string) Str::uuid(), $posted->stockOperation, 'Direct reversal'));
        foreach ([$posted, $posted->items->first()] as $record) {
            try {
                $record->update($record instanceof SalesOrder ? ['notes' => 'Tampered'] : ['quantity' => 99]);
                $this->fail('Posted history must be immutable.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(10, (int) Inventory::sum('quantity'));
    }

    public function test_duplicate_product_unit_pairs_bad_quantities_overflow_and_future_dates_fail_before_write(): void
    {
        $line = ['product_id' => $this->product->id, 'quantity' => 1, 'unit' => 'piece'];
        $this->assertSaleInvalid(fn () => $this->sale(['items' => [$line, $line]]));
        foreach ([0, -1, '1.5', ['bad']] as $quantity) {
            $this->assertSaleInvalid(fn () => $this->sale(['items' => [[...$line, 'quantity' => $quantity]]]));
        }
        $this->assertSaleInvalid(fn () => $this->sale(['order_date' => '2026-11-04']));
        $this->product->update(['selling_price' => '999999999.999999']);
        $this->assertSaleInvalid(fn () => $this->sale(['items' => [[...$line, 'quantity' => 2147483647]]]));
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_confirmation_requires_both_sales_post_and_inventory_post_and_stale_actor_is_denied(): void
    {
        $order = $this->sale();
        $role = Role::where('code', 'sales')->firstOrFail();
        $role->permissions()->sync(Permission::where('code', 'sales.post')->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id])->refresh();
        $this->expectException(AuthorizationException::class);
        app(SalesOrders::class)->confirm($user, $order, $this->confirmation($order));
    }
}
