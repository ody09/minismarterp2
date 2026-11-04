<?php

namespace Tests\Support;

use App\Models\Supplier;
use App\Services\CustomerPricing;
use App\Services\PurchaseOrders;
use App\Services\SalesOrders;
use Illuminate\Support\Str;

trait BuildsErpWorkflow
{
    use BuildsSalesData;

    protected function buildErpWorkflow(): array
    {
        $this->buildSalesData();
        $supplier = Supplier::create(['name' => 'Workflow supplier', 'is_active' => true]);
        $productB = $this->product->replicate();
        $productB->fill(['name' => 'Workflow B', 'pieces_per_carton' => 6, 'purchase_price' => '3.500001', 'selling_price' => '1.250001'])->save();
        $purchases = app(PurchaseOrders::class);
        $purchase = $purchases->save($this->actor, ['key' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'order_date' => '2026-11-03',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit' => 'carton', 'unit_price' => '1.234567'],
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit' => 'carton', 'unit_price' => '1.234567'],
                ['product_id' => $productB->id, 'quantity' => 2, 'unit' => 'carton', 'unit_price' => '3.500001']]]);
        $receiptData = ['key' => (string) Str::uuid(), 'version' => 1, 'items' => $purchase->items->map(fn ($item) => [
            'item_id' => $item->id, 'batch_number' => 'WF-'.$item->line_number,
            'expiration_date' => [1 => '2026-11-05', 2 => '2026-11-10', 3 => '2026-11-20'][$item->line_number]])->all()];
        $purchase = $purchases->receive($this->actor, $purchase, $receiptData);
        app(CustomerPricing::class)->save($this->actor, $this->priceData(['min_quantity' => 30, 'unit_price' => '2.000001']));
        $sales = app(SalesOrders::class);
        $sale = $sales->save($this->actor, $this->saleData(['items' => [
            ['product_id' => $this->product->id, 'quantity' => 30, 'unit' => 'piece'],
            ['product_id' => $productB->id, 'quantity' => 1, 'unit' => 'carton']]]));
        $confirmationData = ['key' => (string) Str::uuid(), 'version' => 1];
        $sale = $sales->confirm($this->actor, $sale, $confirmationData);

        return compact('supplier', 'productB', 'purchase', 'sale', 'receiptData', 'confirmationData');
    }
}
