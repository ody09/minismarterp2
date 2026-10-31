<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrders;
use App\Services\StockLedger;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccessControlSeeder::class, MasterDataSeeder::class]);
        $this->actor = User::factory()->create(['role_id' => Role::where('code', 'admin')->firstOrFail()->id])->refresh();
        $this->supplier = Supplier::create(['name' => 'Purchase supplier', 'is_active' => true]);
        $category = Category::create(['name' => 'Purchase category', 'is_active' => true]);
        $this->product = Product::create(['category_id' => $category->id, 'name' => 'Purchase product',
            'pieces_per_carton' => 24, 'purchase_unit' => 'carton', 'selling_unit' => 'piece',
            'purchase_price' => '12.345678', 'selling_price' => '1', 'reorder_level' => 0, 'is_active' => true]);
    }

    private function data(array $changes = []): array
    {
        return array_replace(['key' => (string) Str::uuid(), 'supplier_id' => $this->supplier->id,
            'order_date' => '2026-10-30', 'notes' => 'Purchase notes', 'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3, 'unit' => 'carton', 'unit_price' => '12.345678'],
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit' => 'piece', 'unit_price' => '0.100001'],
            ]], $changes);
    }

    private function save(array $changes = []): PurchaseOrder
    {
        return app(PurchaseOrders::class)->save($this->actor, $this->data($changes));
    }

    private function invalid(callable $action, ?string $field = null): void
    {
        try {
            $action();
            $this->fail('Invalid purchase action must fail.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
            if ($field) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    public function test_draft_calculates_exact_six_decimal_totals_and_preserves_price_snapshots(): void
    {
        $order = $this->save(['total_amount' => '0', 'status' => 'received', 'user_id' => 999]);
        $this->assertSame('37.237036', $order->total_amount);
        $this->assertSame('draft', $order->status);
        $this->assertSame($this->actor->id, $order->user_id);
        $this->assertSame(['37.037034', '0.200002'], $order->items->pluck('subtotal')->all());
        $this->assertSame([24, 1], $order->items->pluck('conversion_factor')->all());
        $this->product->update(['purchase_price' => '99']);
        $this->assertSame('12.345678', $order->items()->firstOrFail()->unit_price);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_duplicate_draft_submission_replays_but_changed_payload_cannot_reuse_key(): void
    {
        $data = $this->data();
        $one = app(PurchaseOrders::class)->save($this->actor, $data);
        $two = app(PurchaseOrders::class)->save($this->actor, $data);
        $this->assertSame($one->id, $two->id);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->invalid(fn () => app(PurchaseOrders::class)->save($this->actor, [...$data, 'notes' => 'Changed']));
    }

    public function test_draft_update_rejects_stale_forms_and_cancelled_order_keeps_its_items(): void
    {
        $order = $this->save();
        $data = $this->data(['version' => 1, 'notes' => 'Updated']);
        $updated = app(PurchaseOrders::class)->save($this->actor, $data, $order);
        $this->assertSame(2, $updated->version);
        $this->invalid(fn () => app(PurchaseOrders::class)->save($this->actor, $data, $order));
        $this->invalid(fn () => app(PurchaseOrders::class)->cancel($this->actor, $order, 1));
        $cancelled = app(PurchaseOrders::class)->cancel($this->actor, $order, 2);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(2, $cancelled->items()->count());
        $this->invalid(fn () => app(PurchaseOrders::class)->save($this->actor, $this->data(['version' => 3]), $order));
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_invalid_money_quantities_and_excessive_totals_never_write_a_draft(): void
    {
        foreach (['1e3', '-1', '0.0000001', '1000000000', ['12'], 1.5] as $price) {
            $this->invalid(fn () => $this->save(['items' => [['product_id' => $this->product->id,
                'quantity' => 1, 'unit' => 'piece', 'unit_price' => $price]]]));
        }
        $this->invalid(fn () => $this->save(['items' => [['product_id' => $this->product->id,
            'quantity' => 2147483647, 'unit' => 'piece', 'unit_price' => '999999999.999999']]]));
        $this->invalid(fn () => $this->save(['items' => [['product_id' => $this->product->id,
            'quantity' => 0, 'unit' => 'piece', 'unit_price' => '0']]]));
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_inactive_supplier_or_product_cannot_be_used_for_drafts(): void
    {
        $this->supplier->update(['is_active' => false]);
        $this->invalid(fn () => $this->save());
        $this->supplier->update(['is_active' => true]);
        $this->product->update(['is_active' => false]);
        $this->invalid(fn () => $this->save());
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_purchase_draft_http_entry_is_available_to_authorized_users(): void
    {
        $this->actingAs($this->actor)->withSession(['auth_version' => $this->actor->auth_version]);
        $this->post('/purchases', $this->data())->assertRedirect();
        $this->assertDatabaseHas('purchase_orders', ['supplier_id' => $this->supplier->id, 'status' => 'draft', 'total_amount' => '37.237036']);
    }

    private function receipt(PurchaseOrder $order, array $changes = []): array
    {
        return array_replace(['key' => (string) Str::uuid(), 'version' => $order->version,
            'items' => $order->items->map(fn ($item) => ['item_id' => $item->id, 'batch_number' => 'NEW-'.$item->line_number,
                'production_date' => '2026-10-01', 'expiration_date' => '2099-12-31'])->all()], $changes);
    }

    public function test_full_receipt_creates_240_pieces_and_a_typed_auditable_link_exactly_once(): void
    {
        $order = $this->save(['items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit' => 'carton', 'unit_price' => '12.5']]]);
        $data = $this->receipt($order);
        $received = app(PurchaseOrders::class)->receive($this->actor, $order, $data);
        $replay = app(PurchaseOrders::class)->receive($this->actor, $order, $data);
        $this->assertSame('received', $received->status);
        $this->assertSame(2, $received->version);
        $this->assertSame($this->actor->id, $received->received_by);
        $this->assertNotNull($received->received_at);
        $this->assertSame($received->stock_operation_id, $replay->stock_operation_id);
        $this->assertSame(240, Inventory::firstOrFail()->quantity);
        $this->assertSame(240, (int) InventoryTransaction::sum('quantity'));
        $this->assertDatabaseCount('stock_operations', 1);
        $this->assertSame($order->id, $received->stockOperation->purchaseOrder->id);
        $this->assertSame('PO:'.$order->id, $received->stockOperation->reference);
        $this->assertNotNull($received->items->first()->batch_id);
        $this->invalid(fn () => app(PurchaseOrders::class)->receive($this->actor, $order, $this->receipt($order)));
        $this->invalid(fn () => app(PurchaseOrders::class)->receive($this->actor, $order, [...$data, 'version' => 99]));
        $this->artisan('erp:check-stock')->assertSuccessful();
    }

    public function test_receipt_uses_only_saved_quantities_prices_and_existing_matching_batches(): void
    {
        $order = $this->save();
        $batch = ProductBatch::create(['product_id' => $this->product->id, 'batch_number' => 'EXISTING']);
        $data = $this->receipt($order, ['items' => $order->items->map(fn ($item) => ['item_id' => $item->id,
            'batch_id' => $batch->id, 'quantity' => 999, 'unit_price' => 0])->all(), 'total_amount' => 0]);
        $result = app(PurchaseOrders::class)->receive($this->actor, $order, $data);
        $this->assertSame(74, Inventory::firstOrFail()->quantity);
        $this->assertSame('37.237036', $result->total_amount);
        $this->assertSame([$batch->id, $batch->id], $result->items->pluck('batch_id')->all());
        $this->assertDatabaseCount('product_batches', 1);
    }

    public function test_receipt_rejects_stale_draft_changed_packing_and_inactive_data(): void
    {
        $order = $this->save();
        $data = $this->receipt($order);
        $this->product->update(['pieces_per_carton' => 12]);
        $this->invalid(fn () => app(PurchaseOrders::class)->receive($this->actor, $order, $data));
        $this->product->update(['pieces_per_carton' => 24, 'is_active' => false]);
        $this->invalid(fn () => app(PurchaseOrders::class)->receive($this->actor, $order, $data));
        $this->product->update(['is_active' => true]);
        $this->supplier->update(['is_active' => false]);
        $this->invalid(fn () => app(PurchaseOrders::class)->receive($this->actor, $order, $data));
        $this->supplier->update(['is_active' => true]);
        app(PurchaseOrders::class)->save($this->actor, $this->data(['version' => 1]), $order);
        $this->invalid(fn () => app(PurchaseOrders::class)->receive($this->actor, $order, $data));
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('product_batches', 0);
    }

    public function test_receipt_rejects_missing_duplicate_foreign_and_expired_batch_assignments(): void
    {
        $order = $this->save();
        $data = $this->receipt($order);
        $this->invalid(fn () => app(PurchaseOrders::class)->receive($this->actor, $order, [...$data, 'items' => [$data['items'][0]]]));
        $this->invalid(fn () => app(PurchaseOrders::class)->receive($this->actor, $order, [...$data, 'items' => [$data['items'][0], $data['items'][0]]]));
        $other = $this->save();
        $this->invalid(fn () => app(PurchaseOrders::class)->receive($this->actor, $order, $this->receipt($other)));
        $foreignProduct = $this->product->replicate();
        $foreignProduct->save();
        $foreignBatch = ProductBatch::create(['product_id' => $foreignProduct->id, 'batch_number' => 'FOREIGN']);
        foreach ([$foreignBatch, ProductBatch::create(['product_id' => $this->product->id, 'batch_number' => 'EXPIRED', 'expiration_date' => '2020-01-01'])] as $batch) {
            $items = $order->items->map(fn ($item) => ['item_id' => $item->id, 'batch_id' => $batch->id])->all();
            $this->invalid(fn () => app(PurchaseOrders::class)->receive($this->actor, $order, [...$data, 'items' => $items]));
        }
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_second_movement_failure_rolls_back_new_batches_document_items_and_stock(): void
    {
        $order = $this->save();
        $created = 0;
        Event::listen('eloquent.creating: '.InventoryTransaction::class, function () use (&$created) {
            if (++$created === 2) {
                throw new \RuntimeException('Failed second purchase movement');
            }
        });
        try {
            app(PurchaseOrders::class)->receive($this->actor, $order, $this->receipt($order));
            $this->fail('Expected transaction failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Failed second purchase movement', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.InventoryTransaction::class);
        }
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertNull($order->fresh()->stock_operation_id);
        $this->assertSame([null, null], $order->items()->pluck('batch_id')->all());
        foreach (['product_batches', 'inventory', 'stock_operations', 'inventory_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertNull($this->product->fresh()->stock_started_at);
    }

    public function test_terminal_documents_cannot_be_edited_cancelled_or_generically_reversed(): void
    {
        $order = $this->save();
        $received = app(PurchaseOrders::class)->receive($this->actor, $order, $this->receipt($order));
        $this->invalid(fn () => app(PurchaseOrders::class)->save($this->actor, $this->data(['version' => 2]), $received));
        $this->invalid(fn () => app(PurchaseOrders::class)->cancel($this->actor, $received, 2));
        $this->invalid(fn () => app(StockLedger::class)->reverse($this->actor, (string) Str::uuid(), $received->stockOperation, 'Direct reverse'));
        try {
            $received->update(['notes' => 'Tampered']);
            $this->fail('Received model must be immutable.');
        } catch (\LogicException) {
            $this->assertSame('Purchase notes', $received->fresh()->notes);
        }
        $cancelled = $this->save();
        $cancelled = app(PurchaseOrders::class)->cancel($this->actor, $cancelled, 1);
        $this->invalid(fn () => app(PurchaseOrders::class)->receive($this->actor, $cancelled, $this->receipt($cancelled)));
        $this->assertSame(74, (int) Inventory::sum('quantity'));
    }

    public function test_receiving_requires_both_document_receive_and_stock_post_permissions(): void
    {
        $order = $this->save();
        $role = Role::where('code', 'purchasing')->firstOrFail();
        $role->permissions()->sync(Permission::where('code', 'purchases.receive')->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id])->refresh();
        $this->expectException(AuthorizationException::class);
        app(PurchaseOrders::class)->receive($user, $order, $this->receipt($order));
    }

    public function test_purchase_http_flow_filters_locales_and_history_are_available(): void
    {
        $this->actingAs($this->actor)->withSession(['auth_version' => $this->actor->auth_version, 'locale' => 'en']);
        $this->get('/purchases')->assertOk();
        $this->get('/purchases/create')->assertOk()->assertSee('Purchase product');
        $this->post('/purchases', $this->data())->assertRedirect();
        $order = PurchaseOrder::firstOrFail();
        $this->get('/purchases/'.$order->id)->assertOk()->assertSee('37.237036')->assertSee('Purchase supplier');
        $this->get('/purchases/'.$order->id.'/edit')->assertOk();
        $this->get('/purchases/'.$order->id.'/receive')->assertOk()->assertSee('Purchase product');
        $this->get('/purchases?status=received')->assertOk()->assertDontSee($order->number());
        $this->get('/purchases?supplier_id='.$this->supplier->id.'&from=2026-10-30&to=2026-10-30')->assertOk()->assertSee($order->number());
        $this->post('/purchases/'.$order->id.'/receive', $this->receipt($order->load('items')))->assertRedirect('/purchases/'.$order->id);
        $this->get('/purchases/'.$order->id)->assertOk()->assertSee('NEW-1');
        $this->get('/purchases/'.$order->id.'/edit')->assertRedirect('/purchases/'.$order->id);
        $this->withSession(['locale' => 'ar'])->get('/purchases')->assertOk()->assertSee('أوامر الشراء');
        $this->get('/suppliers')->assertOk()->assertSee('supplier_id='.$this->supplier->id, false);
        $this->delete('/purchases/'.$order->id)->assertStatus(405);
    }

    public function test_http_update_and_cancel_preserve_history_without_stock(): void
    {
        $order = $this->save();
        $this->actingAs($this->actor)->withSession(['auth_version' => $this->actor->auth_version]);
        $this->patch('/purchases/'.$order->id, $this->data(['version' => 1, 'notes' => 'Changed']))->assertRedirect();
        $this->post('/purchases/'.$order->id.'/cancel', ['version' => 2])->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_unprivileged_users_cannot_read_manage_or_receive_purchases(): void
    {
        $order = $this->save();
        $user = User::factory()->create(['role_id' => Role::where('code', 'sales')->firstOrFail()->id])->refresh();
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
        foreach (['/purchases', '/purchases/create', '/purchases/'.$order->id, '/purchases/'.$order->id.'/edit', '/purchases/'.$order->id.'/receive'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/purchases', $this->data())->assertForbidden();
        $this->patch('/purchases/'.$order->id, $this->data(['version' => 1]))->assertForbidden();
        $this->post('/purchases/'.$order->id.'/cancel', ['version' => 1])->assertForbidden();
        $this->post('/purchases/'.$order->id.'/receive', $this->receipt($order))->assertForbidden();
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_malformed_old_input_is_safe_and_displayed_content_is_escaped(): void
    {
        $order = $this->save(['notes' => '<script>alert(1)</script>']);
        $this->actingAs($this->actor)->withSession(['auth_version' => $this->actor->auth_version]);
        $this->get('/purchases/'.$order->id)->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        foreach ([['items' => 'invalid'], ['items' => [null, ['product_id' => ['bad'], 'unit_price' => ['bad']]]], ['supplier_id' => ['bad'], 'notes' => ['bad']]] as $bad) {
            $this->from('/purchases/create')->post('/purchases', $this->data($bad))->assertSessionHasErrors();
            $this->get('/purchases/create')->assertOk();
        }
        $this->from('/purchases/'.$order->id.'/receive')->post('/purchases/'.$order->id.'/receive',
            $this->receipt($order, ['items' => [['item_id' => [$order->items->first()->id], 'batch_number' => ['bad']]]]))->assertSessionHasErrors();
        $this->get('/purchases/'.$order->id.'/receive')->assertOk();
        $this->get('/purchases?status[]=bad')->assertSessionHasErrors();
    }

    public function test_new_batch_dates_can_be_omitted_independently_but_must_be_ordered_when_both_present(): void
    {
        $order = $this->save();
        $data = $this->receipt($order);
        $data['items'][0]['production_date'] = null;
        $data['items'][1]['expiration_date'] = null;
        $received = app(PurchaseOrders::class)->receive($this->actor, $order, $data);
        $this->assertSame('received', $received->status);
        $other = $this->save();
        $bad = $this->receipt($other);
        $bad['items'][0]['production_date'] = '2099-12-31';
        $bad['items'][0]['expiration_date'] = '2099-01-01';
        $this->invalid(fn () => app(PurchaseOrders::class)->receive($this->actor, $other, $bad));
    }

    public function test_zero_batch_number_and_note_are_preserved_as_nonempty_values(): void
    {
        $order = $this->save(['notes' => '0']);
        $data = $this->receipt($order);
        $data['items'][0]['batch_number'] = '0';
        $received = app(PurchaseOrders::class)->receive($this->actor, $order, $data);
        $this->assertSame('0', $received->notes);
        $this->assertSame('0', $received->items->first()->batch->batch_number);
    }
}
