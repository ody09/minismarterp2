<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\StockOperation;
use App\Models\User;
use App\Services\StockLedger;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Product $product;

    private ProductBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 09:00:00 UTC');
        CarbonImmutable::setTestNow('2026-10-30 09:00:00 UTC');
        $this->seed(DatabaseSeeder::class);
        $this->actor = User::factory()->create(['role_id' => Role::where('code', 'admin')->firstOrFail()->id])->refresh();
        $category = Category::create(['name' => 'Stock category', 'is_active' => true]);
        $this->product = Product::create(['name' => 'Stock product', 'category_id' => $category->id,
            'pieces_per_carton' => 24, 'purchase_unit' => 'carton', 'selling_unit' => 'piece',
            'purchase_price' => '12', 'selling_price' => '1', 'reorder_level' => 5, 'is_active' => true]);
        $this->batch = ProductBatch::create(['product_id' => $this->product->id, 'batch_number' => 'B-1',
            'production_date' => '2026-10-01', 'expiration_date' => '2026-11-05']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function receive(int $quantity = 10, string $unit = 'piece', ?ProductBatch $batch = null, ?string $key = null, string $reference = 'PO:1'): StockOperation
    {
        return app(StockLedger::class)->receive($this->actor, $key ?? (string) Str::uuid(), $reference,
            [['batch_id' => ($batch ?? $this->batch)->id, 'quantity' => $quantity, 'unit' => $unit]]);
    }

    private function issue(int $quantity, string $reference = 'SO:1'): StockOperation
    {
        return app(StockLedger::class)->issue($this->actor, (string) Str::uuid(), $reference,
            [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit' => 'piece']]);
    }

    private function assertInvalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The invalid stock operation should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_stock_screens_are_read_only_and_zero_products_have_no_fabricated_movements(): void
    {
        $this->actingAs($this->actor)->withSession(['auth_version' => $this->actor->auth_version]);
        $this->get('/inventory')->assertOk()->assertSee('Stock product');
        $this->get('/inventory/products/'.$this->product->id)->assertOk();
        $this->get('/inventory/transactions')->assertOk();
        $this->post('/inventory', [])->assertStatus(405);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('inventory', 0);
    }

    public function test_receipt_and_issue_use_integer_pieces_and_match_the_ledger(): void
    {
        $receipt = $this->receive(10, 'carton');
        $sale = $this->issue(51);
        $this->assertSame(189, Inventory::firstOrFail()->quantity);
        $this->assertSame(189, (int) InventoryTransaction::sum('quantity'));
        $this->assertSame(240, $receipt->transactions()->firstOrFail()->quantity);
        $this->assertSame(-51, $sale->transactions()->firstOrFail()->quantity);
        $this->assertNotNull($this->product->fresh()->stock_started_at);
        $this->assertNotNull($this->batch->fresh()->stock_started_at);
        $this->artisan('erp:check-stock')->assertSuccessful();
    }

    public function test_identical_replays_return_the_same_operation_without_adding_stock(): void
    {
        $key = (string) Str::uuid();
        $one = $this->receive(key: $key);
        $two = $this->receive(key: $key);
        $this->assertSame($one->id, $two->id);
        $this->assertDatabaseCount('stock_operations', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertSame(10, Inventory::firstOrFail()->quantity);
        $this->assertInvalid(fn () => $this->receive(11, key: $key));
        $this->assertInvalid(fn () => $this->receive(reference: 'PO:1'));
        $this->assertSame(10, Inventory::firstOrFail()->quantity);
    }

    public function test_fefo_splits_one_issue_across_batches_and_records_allocations(): void
    {
        $later = ProductBatch::create(['product_id' => $this->product->id, 'batch_number' => 'B-LATE', 'expiration_date' => '2026-12-01']);
        $this->receive(20, batch: $later, reference: 'PO:LATE');
        $this->receive(10, reference: 'PO:EARLY');
        $sale = $this->issue(25);
        $moves = $sale->transactions()->with('inventory')->orderBy('allocation_number')->get();
        $this->assertSame([$this->batch->id, $later->id], $moves->map(fn ($move) => $move->inventory->batch_id)->all());
        $this->assertSame([-10, -15], $moves->pluck('quantity')->all());
        $this->assertSame([1, 1], $moves->pluck('line_number')->all());
        $this->assertSame(5, (int) Inventory::sum('quantity'));
    }

    public function test_expiry_free_batches_sort_after_dated_batches(): void
    {
        $undated = ProductBatch::create(['product_id' => $this->product->id, 'batch_number' => 'NO-EXPIRY']);
        $this->receive(4, batch: $undated, reference: 'PO:NONE');
        $this->receive(2, reference: 'PO:DATED');
        $issue = $this->issue(3);
        $this->assertSame([-2, -1], $issue->transactions()->orderBy('allocation_number')->pluck('quantity')->all());
        $this->assertSame(3, Inventory::where('batch_id', $undated->id)->firstOrFail()->quantity);
    }

    public function test_movement_and_operation_details_render_with_real_allocations_and_date_filters(): void
    {
        $receipt = $this->receive();
        $this->issue(3);
        $this->actingAs($this->actor)->withSession(['auth_version' => $this->actor->auth_version, 'locale' => 'en']);
        $this->get('/inventory/transactions?q=PO:1&from=2026-10-30&to=2026-10-30')->assertOk()->assertSee('PO:1')->assertDontSee('SO:1');
        $this->get('/inventory/operations/'.$receipt->id)->assertOk()->assertSee('Stock product')->assertSee('B-1');
        $this->get('/inventory/products/'.$this->product->id)->assertOk()->assertSee('B-1');
    }

    public function test_posted_history_cannot_be_edited_or_deleted_through_models(): void
    {
        $operation = $this->receive();
        $movement = $operation->transactions()->firstOrFail();
        foreach ([$operation, $movement] as $record) {
            foreach (['save', 'delete'] as $action) {
                try {
                    if ($action === 'save') {
                        $record->setAttribute($record instanceof StockOperation ? 'reference' : 'quantity', $record instanceof StockOperation ? 'CHANGED' : 999);
                    }
                    $record->{$action}();
                    $this->fail('Posted history should be immutable.');
                } catch (\LogicException) {
                    $this->addToAssertionCount(1);
                    $record->refresh();
                }
            }
        }
        $this->assertSame(10, $movement->fresh()->quantity);
        $this->assertSame('PO:1', $operation->fresh()->reference);
    }

    public function test_positive_posting_cannot_overflow_the_total_across_product_batches(): void
    {
        $this->receive();
        $other = ProductBatch::create(['product_id' => $this->product->id, 'batch_number' => 'OVERFLOW']);
        $this->receive(4, batch: $other, reference: 'PO:OTHER');
        DB::table('inventory')->where('batch_id', $this->batch->id)->update(['quantity' => PHP_INT_MAX - 5]);
        $this->assertInvalid(fn () => $this->receive(2, batch: $other, reference: 'PO:OVERFLOW'));
        $this->assertSame(4, Inventory::where('batch_id', $other->id)->firstOrFail()->quantity);
    }

    public function test_reversing_an_issue_restores_physical_but_not_sellable_expired_inactive_stock(): void
    {
        $this->receive();
        $issue = $this->issue(4);
        $this->product->update(['is_active' => false]);
        CarbonImmutable::setTestNow('2026-11-06 09:00:00 UTC');
        Carbon::setTestNow('2026-11-06 09:00:00 UTC');
        app(StockLedger::class)->reverse($this->actor, (string) Str::uuid(), $issue, 'Returned goods');
        $this->assertSame(10, Inventory::firstOrFail()->quantity);
        $this->actingAs($this->actor)->withSession(['auth_version' => $this->actor->auth_version]);
        $this->get('/inventory')->assertOk()->assertViewHas('products', fn ($products) => (int) $products->first()->sellable_quantity === 0);
    }

    public function test_insufficient_stock_and_duplicate_product_lines_roll_back_the_entire_issue(): void
    {
        $this->receive(10);
        $this->assertInvalid(fn () => $this->issue(11));
        $this->assertInvalid(fn () => app(StockLedger::class)->issue($this->actor, (string) Str::uuid(), 'SO:MULTI', [
            ['product_id' => $this->product->id, 'quantity' => 7, 'unit' => 'piece'],
            ['product_id' => $this->product->id, 'quantity' => 7, 'unit' => 'piece'],
        ]));
        $this->assertSame(10, Inventory::firstOrFail()->quantity);
        $this->assertDatabaseCount('stock_operations', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);
    }

    public function test_failure_during_movement_writing_rolls_back_balances_operations_and_stamps(): void
    {
        $created = 0;
        Event::listen('eloquent.creating: '.InventoryTransaction::class, function () use (&$created) {
            if (++$created === 2) {
                throw new \RuntimeException('Simulated second movement failure.');
            }
        });
        try {
            app(StockLedger::class)->receive($this->actor, (string) Str::uuid(), 'PO:FAIL', [
                ['batch_id' => $this->batch->id, 'quantity' => 2, 'unit' => 'piece'],
                ['batch_id' => $this->batch->id, 'quantity' => 3, 'unit' => 'piece'],
            ]);
            $this->fail('Expected a write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated second movement failure.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.InventoryTransaction::class);
        }
        $this->assertDatabaseCount('inventory', 0);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertNull($this->product->fresh()->stock_started_at);
        $this->assertNull($this->batch->fresh()->stock_started_at);
    }

    public function test_reversal_is_idempotent_preserves_history_and_cannot_be_reversed_again(): void
    {
        $this->receive(10);
        $sale = $this->issue(7);
        $key = (string) Str::uuid();
        $reverse = app(StockLedger::class)->reverse($this->actor, $key, $sale, 'Document cancelled');
        $replay = app(StockLedger::class)->reverse($this->actor, $key, $sale, 'Document cancelled');
        $this->assertSame($reverse->id, $replay->id);
        $this->assertSame(10, Inventory::firstOrFail()->quantity);
        $this->assertDatabaseCount('inventory_transactions', 3);
        $this->assertSame($sale->id, $reverse->reversal_of_id);
        $this->assertInvalid(fn () => app(StockLedger::class)->reverse($this->actor, (string) Str::uuid(), $sale, 'Again'));
        $this->assertInvalid(fn () => app(StockLedger::class)->reverse($this->actor, (string) Str::uuid(), $reverse, 'Again'));
    }

    public function test_receipt_reversal_cannot_remove_stock_already_consumed(): void
    {
        $receipt = $this->receive(10);
        $this->issue(7);
        $this->assertInvalid(fn () => app(StockLedger::class)->reverse($this->actor, (string) Str::uuid(), $receipt, 'Cancelled receipt'));
        $this->assertSame(3, Inventory::firstOrFail()->quantity);
        $this->assertDatabaseCount('stock_operations', 2);
    }

    public function test_expiry_uses_aden_day_boundary_without_changing_physical_stock(): void
    {
        $this->batch->update(['expiration_date' => '2026-10-30']);
        $this->receive(10);
        Carbon::setTestNow('2026-10-30 22:00:00 UTC');
        CarbonImmutable::setTestNow('2026-10-30 22:00:00 UTC');
        $this->assertInvalid(fn () => $this->issue(1));
        $this->actingAs($this->actor)->withSession(['auth_version' => $this->actor->auth_version]);
        $this->get('/inventory')->assertOk()->assertViewHas('products', function ($products) {
            $row = $products->first();

            return (int) $row->physical_quantity === 10 && (int) $row->sellable_quantity === 0 && (int) $row->expired_quantity === 10;
        });
        $this->assertSame(10, Inventory::firstOrFail()->quantity);
        $this->assertDatabaseCount('inventory_transactions', 1);
    }

    public function test_expired_receipts_and_invalid_inputs_are_rejected(): void
    {
        $this->batch->update(['expiration_date' => '2026-10-29']);
        $this->assertInvalid(fn () => $this->receive());
        foreach ([['quantity' => 0, 'unit' => 'piece'], ['quantity' => -1, 'unit' => 'piece'], ['quantity' => '1.5', 'unit' => 'piece'], ['quantity' => 1, 'unit' => 'pallet']] as $line) {
            $this->assertInvalid(fn () => app(StockLedger::class)->receive($this->actor, (string) Str::uuid(), 'PO:INVALID', [['batch_id' => $this->batch->id, ...$line]]));
        }
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('inventory', 0);
    }

    public function test_stock_permissions_apply_to_internal_calls_and_http_read_screens(): void
    {
        $user = User::factory()->create(['role_id' => Role::where('code', 'sales')->firstOrFail()->id])->refresh();
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version])->get('/inventory')->assertForbidden();
        $this->get('/inventory/transactions')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(StockLedger::class)->receive($user, (string) Str::uuid(), 'PO:FORGED', [['batch_id' => $this->batch->id, 'quantity' => 1, 'unit' => 'piece']]);
    }

    public function test_history_prevents_deletion_and_freezes_packing_and_batch_metadata_even_at_zero_stock(): void
    {
        $receipt = $this->receive();
        app(StockLedger::class)->reverse($this->actor, (string) Str::uuid(), $receipt, 'Receipt cancelled');
        $this->actingAs($this->actor)->withSession(['auth_version' => $this->actor->auth_version]);
        $data = $this->product->fresh()->only(['name', 'description', 'category_id', 'pieces_per_carton', 'purchase_unit', 'selling_unit', 'purchase_price', 'selling_price', 'reorder_level', 'is_active']);
        $this->patch('/products/'.$this->product->id, [...$data, 'pieces_per_carton' => 12])->assertSessionHasErrors('pieces_per_carton');
        $this->patch('/products/'.$this->product->id, [...$data, 'name' => 'Renamed product'])->assertRedirect('/products');
        $this->patch('/batches/'.$this->batch->id, ['product_id' => $this->product->id, 'batch_number' => 'CHANGED',
            'production_date' => '2026-10-01', 'expiration_date' => '2026-11-05'])->assertSessionHasErrors('record');
        $this->delete('/batches/'.$this->batch->id)->assertSessionHasErrors('record');
        $this->assertSame(24, $this->product->fresh()->pieces_per_carton);
        $this->assertSame(0, Inventory::firstOrFail()->quantity);
    }

    public function test_reconciliation_reports_a_mismatch_without_adjusting_it(): void
    {
        $this->receive();
        DB::table('inventory')->update(['quantity' => 9]);
        $this->artisan('erp:check-stock')->assertFailed();
        $this->assertSame(9, Inventory::firstOrFail()->quantity);
        $this->assertSame(10, (int) InventoryTransaction::sum('quantity'));
    }
}
