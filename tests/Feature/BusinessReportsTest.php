<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Permission;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessReports;
use App\Services\SalesOrders;
use App\Services\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsErpWorkflow;
use Tests\TestCase;

class BusinessReportsTest extends TestCase
{
    use BuildsErpWorkflow, RefreshDatabase;

    private array $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = $this->buildErpWorkflow();
    }

    public function test_actual_financial_totals_use_document_and_item_snapshots_without_multiplying_fefo_allocations(): void
    {
        $reports = app(BusinessReports::class);
        $sales = $reports->financial($this->actor, 'sales', ['from' => '2026-11-03', 'to' => '2026-11-03', 'customer_id' => $this->customer->id]);
        $purchases = $reports->financial($this->actor, 'purchases', ['supplier_id' => $this->workflow['supplier']->id]);
        $this->assertSame(['count' => 1, 'amount' => '67.500036', 'pieces' => '36'], $sales['summary']);
        $this->assertSame(['count' => 1, 'amount' => '9.469136', 'pieces' => '60'], $purchases['summary']);
        $this->assertSame($this->workflow['sale']->id, $sales['orders']->first()->id);
        $this->assertSame(36, (int) $sales['orders']->first()->total_pieces);
        $this->assertDatabaseCount('inventory_transactions', 6);
    }

    public function test_drafts_cancelled_orders_and_other_customers_are_not_included_in_confirmed_report(): void
    {
        $sales = app(SalesOrders::class);
        $sales->save($this->actor, $this->saleData());
        $cancel = $sales->save($this->actor, $this->saleData());
        $sales->cancel($this->actor, $cancel, 1);
        $other = Customer::create(['name' => 'Other customer', 'is_active' => true]);
        $order = $sales->save($this->actor, $this->saleData(['customer_id' => $other->id, 'items' => [
            ['product_id' => $this->workflow['productB']->id, 'quantity' => 1, 'unit' => 'piece']]]));
        $sales->confirm($this->actor, $order, ['key' => (string) Str::uuid(), 'version' => 1]);
        $result = app(BusinessReports::class)->financial($this->actor, 'sales', ['customer_id' => $this->customer->id]);
        $this->assertSame(['count' => 1, 'amount' => '67.500036', 'pieces' => '36'], $result['summary']);
    }

    public function test_posting_day_filters_use_inclusive_aden_boundaries_not_commercial_order_date(): void
    {
        $sales = app(SalesOrders::class);
        foreach (['2026-11-02 20:59:59 UTC', '2026-11-02 21:00:00 UTC', '2026-11-03 20:59:59 UTC', '2026-11-03 21:00:00 UTC'] as $instant) {
            CarbonImmutable::setTestNow($instant);
            $order = $sales->save($this->actor, $this->saleData(['order_date' => '2026-11-02', 'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit' => 'piece']]]));
            $sales->confirm($this->actor, $order, ['key' => (string) Str::uuid(), 'version' => 1]);
        }
        CarbonImmutable::setTestNow('2026-11-03 09:00:00 UTC');
        $result = app(BusinessReports::class)->financial($this->actor, 'sales', ['from' => '2026-11-03', 'to' => '2026-11-03']);
        $this->assertSame(['count' => 3, 'amount' => '72.500038', 'pieces' => '38'], $result['summary']);
    }

    public function test_summaries_cover_all_matching_pages_and_not_just_displayed_rows(): void
    {
        $sales = app(SalesOrders::class);
        for ($i = 0; $i < 17; $i++) {
            $order = $sales->save($this->actor, $this->saleData(['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit' => 'piece']]]));
            $sales->confirm($this->actor, $order, ['key' => (string) Str::uuid(), 'version' => 1]);
        }
        $result = app(BusinessReports::class)->financial($this->actor, 'sales', []);
        $this->assertSame(['count' => 18, 'amount' => '110.000053', 'pieces' => '53'], $result['summary']);
        $this->assertSame(15, $result['orders']->count());
        $this->assertSame(18, $result['orders']->total());
    }

    public function test_stock_alerts_use_sellable_for_low_stock_and_positive_physical_batches_for_expiry(): void
    {
        $this->product->update(['reorder_level' => 18]);
        $this->workflow['productB']->update(['reorder_level' => 5]);
        $disabled = $this->product->replicate();
        $disabled->fill(['name' => 'Inactive workflow product'])->save();
        $batch = ProductBatch::create(['product_id' => $disabled->id, 'batch_number' => 'DISABLED', 'expiration_date' => '2026-11-03']);
        app(StockLedger::class)->receive($this->actor, (string) Str::uuid(), 'REPORT:DISABLED', [['batch_id' => $batch->id, 'quantity' => 5, 'unit' => 'piece']]);
        $disabled->update(['is_active' => false]);
        CarbonImmutable::setTestNow('2026-11-01 09:00:00 UTC');
        $expired = ProductBatch::create(['product_id' => $this->product->id, 'batch_number' => 'EXPIRED', 'expiration_date' => '2026-11-02']);
        app(StockLedger::class)->receive($this->actor, (string) Str::uuid(), 'REPORT:EXPIRED', [['batch_id' => $expired->id, 'quantity' => 4, 'unit' => 'piece']]);
        CarbonImmutable::setTestNow('2026-11-03 09:00:00 UTC');
        $undated = ProductBatch::create(['product_id' => $this->workflow['productB']->id, 'batch_number' => 'UNDATED']);
        app(StockLedger::class)->receive($this->actor, (string) Str::uuid(), 'REPORT:UNDATED', [['batch_id' => $undated->id, 'quantity' => 3, 'unit' => 'piece']]);
        $other = Warehouse::create(['code' => 'other', 'name' => 'Other warehouse']);
        Inventory::create(['warehouse_id' => $other->id, 'batch_id' => $expired->id, 'quantity' => 99]);
        $zero = $this->workflow['productB']->replicate();
        $zero->fill(['name' => 'Zero product', 'reorder_level' => 0])->save();
        $result = app(BusinessReports::class)->inventory($this->actor, ['expiry_days' => 7]);
        $this->assertSame(['products' => 4, 'low' => 2, 'near_batches' => 2, 'expired_batches' => 1], $result['summary']);
        $rows = $result['products']->keyBy('id');
        $this->assertSame(22, (int) $rows[$this->product->id]->physical_quantity);
        $this->assertSame(18, (int) $rows[$this->product->id]->sellable_quantity);
        $this->assertSame(18, (int) $rows[$this->product->id]->near_quantity);
        $this->assertSame(0, (int) $rows[$disabled->id]->sellable_quantity);
        $low = app(BusinessReports::class)->inventory($this->actor, ['alert' => 'low', 'expiry_days' => 7]);
        $this->assertSame([$this->product->id, $zero->id], $low['products']->sortBy('id')->pluck('id')->all());
        $this->assertDatabaseCount('inventory_transactions', 9);
    }

    public function test_report_permissions_are_independent_of_dashboard_and_other_report_domains(): void
    {
        $role = Role::where('code', 'sales')->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id])->refresh();
        $dashboard = app(BusinessReports::class)->dashboard($user, []);
        $this->assertArrayNotHasKey('sales', $dashboard);
        $this->assertArrayNotHasKey('purchases', $dashboard);
        $this->assertArrayNotHasKey('inventory', $dashboard);
        $role->permissions()->syncWithoutDetaching(Permission::where('code', 'reports.sales')->pluck('id'));
        $dashboard = app(BusinessReports::class)->dashboard($user, []);
        $this->assertSame('67.500036', $dashboard['sales']['summary']['amount']);
        $this->assertArrayNotHasKey('purchases', $dashboard);
        $this->expectException(AuthorizationException::class);
        app(BusinessReports::class)->financial($user, 'purchases', []);
    }
}
