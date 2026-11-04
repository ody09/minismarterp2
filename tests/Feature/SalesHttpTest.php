<?php

namespace Tests\Feature;

use App\Models\CustomerProductPrice;
use App\Models\Inventory;
use App\Models\Permission;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\SalesOrders;
use App\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsSalesData;
use Tests\TestCase;

class SalesHttpTest extends TestCase
{
    use BuildsSalesData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesData();
        $this->actingAs($this->actor)->withSession(['auth_version' => $this->actor->auth_version, 'locale' => 'en']);
    }

    public function test_customer_price_forms_filtering_updates_and_deactivation_work_without_a_delete_route(): void
    {
        $this->get('/prices')->assertOk();
        $this->get('/prices/create')->assertOk()->assertSee('Sales customer');
        $this->post('/prices', $this->priceData())->assertRedirect('/prices');
        $price = CustomerProductPrice::firstOrFail();
        $this->get('/prices/'.$price->id.'/edit')->assertOk();
        $this->patch('/prices/'.$price->id, $this->priceData(['version' => 1, 'unit_price' => '1.000001']))->assertRedirect('/prices');
        $this->patch('/prices/'.$price->id, $this->priceData(['version' => 1]))->assertSessionHasErrors('version');
        $this->get('/prices?customer_id='.$this->customer->id.'&product_id='.$this->product->id)->assertOk()->assertSee('1.000001');
        $this->patch('/prices/'.$price->id, $this->priceData(['version' => 2, 'is_active' => false]))->assertRedirect();
        $this->get('/prices?active=1')->assertOk()->assertDontSee('1.000001');
        $this->delete('/prices/'.$price->id)->assertStatus(405);
    }

    public function test_sales_http_draft_review_and_confirmation_update_real_stock_and_show_audit_links(): void
    {
        $batch = ProductBatch::create(['product_id' => $this->product->id, 'batch_number' => 'HTTP-BATCH']);
        app(StockLedger::class)->receive($this->actor, (string) Str::uuid(), 'HTTP:RECEIPT', [['batch_id' => $batch->id, 'quantity' => 30, 'unit' => 'piece']]);
        $this->get('/sales/create')->assertOk()->assertSee('Sales product');
        $this->post('/sales', $this->saleData())->assertRedirect();
        $order = SalesOrder::firstOrFail();
        $this->get('/sales/'.$order->id)->assertOk()->assertSee('25.000010')->assertSee('Catalog price');
        $this->get('/sales/'.$order->id.'/confirm')->assertOk()->assertSee('Confirm sale');
        $this->post('/sales/'.$order->id.'/confirm', ['version' => 1, 'key' => (string) Str::uuid(),
            'customer_id' => 999, 'items' => [['quantity' => 999, 'unit_price' => 0]]])->assertRedirect('/sales/'.$order->id);
        $this->assertSame(20, Inventory::firstOrFail()->quantity);
        $this->get('/sales/'.$order->id)->assertOk()->assertSee('Confirmed')->assertSee('/inventory/operations/', false);
        $this->get('/sales/'.$order->id.'/edit')->assertRedirect('/sales/'.$order->id);
        $this->get('/sales/'.$order->id.'/confirm')->assertRedirect('/sales/'.$order->id);
        $this->get('/inventory/operations/'.$order->fresh()->stock_operation_id)->assertOk()->assertSee($order->number());
        $this->delete('/sales/'.$order->id)->assertStatus(405);
    }

    public function test_sales_update_cancel_filters_history_and_both_locales_work(): void
    {
        $order = app(SalesOrders::class)->save($this->actor, $this->saleData());
        $this->get('/sales/'.$order->id.'/edit')->assertOk();
        $this->patch('/sales/'.$order->id, $this->saleData(['version' => 1, 'notes' => 'Changed']))->assertRedirect();
        $this->post('/sales/'.$order->id.'/cancel', ['version' => 2])->assertRedirect();
        $this->get('/sales?customer_id='.$this->customer->id.'&status=cancelled&from=2026-11-03&to=2026-11-03')->assertOk()->assertSee($order->number());
        $this->get('/sales?status=confirmed')->assertOk()->assertDontSee($order->number());
        $this->get('/customers')->assertOk()->assertSee('customer_id='.$this->customer->id, false);
        $this->withSession(['locale' => 'ar'])->get('/sales')->assertOk()->assertSee('أوامر البيع');
        $this->get('/prices')->assertOk()->assertSee('أسعار العملاء');
        $this->get('/roles')->assertOk()->assertDontSee('app.permission_labels.', false);
        $this->withSession(['locale' => 'en'])->get('/roles')->assertOk()->assertDontSee('app.permission_labels.', false);
    }

    public function test_unauthorized_read_and_write_routes_are_denied(): void
    {
        $order = app(SalesOrders::class)->save($this->actor, $this->saleData());
        $user = User::factory()->create(['role_id' => Role::where('code', 'sales')->firstOrFail()->id])->refresh();
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
        foreach (['/sales', '/sales/create', '/sales/'.$order->id, '/sales/'.$order->id.'/edit', '/sales/'.$order->id.'/confirm', '/prices', '/prices/create'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/prices', $this->priceData())->assertForbidden();
        $this->post('/sales', $this->saleData())->assertForbidden();
        $this->post('/sales/'.$order->id.'/confirm', ['key' => (string) Str::uuid(), 'version' => 1])->assertForbidden();
        $this->post('/sales/'.$order->id.'/cancel', ['version' => 1])->assertForbidden();
        $this->patch('/sales/'.$order->id, $this->saleData(['version' => 1]))->assertForbidden();
    }

    public function test_manage_only_roles_have_safe_form_redirects_and_no_implicit_read_access(): void
    {
        $role = Role::where('code', 'sales')->firstOrFail();
        $role->permissions()->sync(Permission::whereIn('code', ['sales.manage', 'prices.manage'])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id])->refresh();
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
        $this->get('/account')->assertOk()->assertSee('/sales/create', false)->assertSee('/prices/create', false);
        $this->post('/sales', $this->saleData())->assertRedirect('/sales/create');
        $this->post('/prices', $this->priceData())->assertRedirect('/prices/create');
        $this->get('/sales')->assertForbidden();
        $this->get('/prices')->assertForbidden();
    }

    public function test_malformed_input_can_be_redisplayed_safely_and_html_is_escaped(): void
    {
        $order = app(SalesOrders::class)->save($this->actor, $this->saleData(['notes' => '<script>alert(1)</script>']));
        $this->get('/sales/'.$order->id)->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        foreach ([['items' => 'bad'], ['items' => [null, ['product_id' => ['bad'], 'quantity' => ['bad']]]], ['customer_id' => ['bad'], 'notes' => ['bad']]] as $bad) {
            $this->from('/sales/create')->post('/sales', $this->saleData($bad))->assertSessionHasErrors();
            $this->get('/sales/create')->assertOk();
        }
        $this->from('/prices/create')->post('/prices', $this->priceData(['unit_price' => ['bad'], 'customer_id' => ['bad']]))->assertSessionHasErrors();
        $this->get('/prices/create')->assertOk();
        $this->get('/sales?status[]=bad')->assertSessionHasErrors();
        $this->get('/prices?customer_id[]=bad')->assertSessionHasErrors();
    }
}
