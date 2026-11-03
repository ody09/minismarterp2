<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Services\PurchaseOrders;
use App\Services\SalesOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsErpWorkflow;
use Tests\TestCase;

class ErpWorkflowTest extends TestCase
{
    use BuildsErpWorkflow, RefreshDatabase;

    public function test_purchase_receipt_and_two_product_sale_preserve_each_fefo_line_batch_and_unit(): void
    {
        $workflow = $this->buildErpWorkflow();
        $purchase = $workflow['purchase'];
        $sale = $workflow['sale'];
        $this->assertSame('9.469136', $purchase->total_amount);
        $this->assertSame('67.500036', $sale->total_amount);
        $moves = $sale->stockOperation->transactions()->with('inventory.batch')->orderBy('line_number')->orderBy('allocation_number')->get();
        $this->assertSame([[1, 1, $this->product->id, 'WF-1', -24, 30, 'piece', 1],
            [1, 2, $this->product->id, 'WF-2', -6, 30, 'piece', 1],
            [2, 1, $workflow['productB']->id, 'WF-3', -6, 1, 'carton', 6]],
            $moves->map(fn ($move) => [$move->line_number, $move->allocation_number, $move->inventory->batch->product_id,
                $move->inventory->batch->batch_number, $move->quantity, $move->input_quantity, $move->unit, $move->conversion_factor])->all());
        $this->assertSame([0, 18, 6], Inventory::orderBy('batch_id')->pluck('quantity')->all());
        $this->assertSame($purchase->stock_operation_id, app(PurchaseOrders::class)->receive($this->actor, $purchase, $workflow['receiptData'])->stock_operation_id);
        $this->assertSame($sale->stock_operation_id, app(SalesOrders::class)->confirm($this->actor, $sale, $workflow['confirmationData'])->stock_operation_id);
        $this->assertDatabaseCount('inventory_transactions', 6);
        $failed = app(SalesOrders::class)->save($this->actor, $this->saleData(['items' => [['product_id' => $this->product->id, 'quantity' => 19, 'unit' => 'piece']]]));
        $this->assertSaleInvalid(fn () => app(SalesOrders::class)->confirm($this->actor, $failed, ['key' => (string) Str::uuid(), 'version' => 1]));
        $this->assertSame('draft', $failed->fresh()->status);
        $this->assertSame(24, (int) Inventory::sum('quantity'));
        $this->assertDatabaseCount('inventory_transactions', 6);
        $this->artisan('erp:check-stock')->assertSuccessful();
    }
}
