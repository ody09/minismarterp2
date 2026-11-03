<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockOperation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessReports;
use App\Services\CustomerPricing;
use App\Services\PurchaseOrders;
use App\Services\SalesOrders;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing']) || DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('Workflow/report verification requires local/testing MySQL.');
}
$tag = '__phase6_verify_'.bin2hex(random_bytes(10));
$fixtures = null;

function workflowAssert(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAIL '.$label);
    }
    echo 'PASS '.$label."\n";
}

try {
    workflowAssert(DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level === 'REPEATABLE-READ', 'mysql_consistent_report_isolation');
    $fixtures = DB::transaction(function () use ($tag) {
        $role = Role::create(['name' => $tag, 'code' => $tag]);
        $role->permissions()->sync(Permission::whereIn('code', ['dashboard.view', 'reports.sales', 'reports.purchases', 'reports.inventory',
            'purchases.manage', 'purchases.receive', 'sales.manage', 'sales.post', 'prices.manage', 'inventory.post'])->pluck('id'));
        $actor = new User;
        $actor->forceFill(['name' => $tag, 'email' => $tag.'@example.test', 'password' => 'A9a'.bin2hex(random_bytes(20)),
            'role_id' => $role->id, 'is_active' => true])->save();
        $category = Category::create(['name' => $tag, 'is_active' => true]);
        $customer = Customer::create(['name' => $tag, 'is_active' => true]);
        $supplier = Supplier::create(['name' => $tag, 'is_active' => true]);
        $products = [];
        foreach (['A' => [24, '2.500001', 20], 'B' => [6, '1.250001', 0], 'C' => [1, '999999999.999999', 0]] as $code => [$factor, $price, $reorder]) {
            $products[$code] = Product::create(['name' => $tag.':'.$code, 'category_id' => $category->id,
                'pieces_per_carton' => $factor, 'purchase_unit' => 'carton', 'selling_unit' => 'piece',
                'purchase_price' => '0', 'selling_price' => $price, 'reorder_level' => $reorder, 'is_active' => true]);
        }

        return ['role' => $role, 'actor' => $actor->refresh(), 'category' => $category, 'customer' => $customer, 'supplier' => $supplier, 'products' => $products];
    });
    ['role' => $role, 'actor' => $actor, 'category' => $category, 'customer' => $customer, 'supplier' => $supplier, 'products' => $products] = $fixtures;
    $purchasing = app(PurchaseOrders::class);
    $selling = app(SalesOrders::class);
    $reports = app(BusinessReports::class);
    $today = CarbonImmutable::now(config('inventory.timezone'))->toDateString();
    $purchase = $purchasing->save($actor, ['key' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'order_date' => $today, 'notes' => $tag,
        'items' => [['product_id' => $products['A']->id, 'quantity' => 1, 'unit' => 'carton', 'unit_price' => '1.234567'],
            ['product_id' => $products['A']->id, 'quantity' => 1, 'unit' => 'carton', 'unit_price' => '1.234567'],
            ['product_id' => $products['B']->id, 'quantity' => 2, 'unit' => 'carton', 'unit_price' => '3.500001']]]);
    $receiptData = ['key' => (string) Str::uuid(), 'version' => 1, 'items' => $purchase->items->map(fn ($item) => [
        'item_id' => $item->id, 'batch_number' => $tag.':batch:'.$item->line_number,
        'expiration_date' => CarbonImmutable::now(config('inventory.timezone'))->addDays([1 => 1, 2 => 7, 3 => 30][$item->line_number])->toDateString()])->all()];
    $purchase = $purchasing->receive($actor, $purchase, $receiptData);
    app(CustomerPricing::class)->save($actor, ['customer_id' => $customer->id, 'product_id' => $products['A']->id,
        'unit' => 'piece', 'min_quantity' => 30, 'unit_price' => '2.000001', 'is_active' => true]);
    $sale = $selling->save($actor, ['key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'order_date' => $today, 'notes' => $tag,
        'items' => [['product_id' => $products['A']->id, 'quantity' => 30, 'unit' => 'piece'],
            ['product_id' => $products['B']->id, 'quantity' => 1, 'unit' => 'carton']]]);
    $confirmation = ['key' => (string) Str::uuid(), 'version' => 1];
    $sale = $selling->confirm($actor, $sale, $confirmation);
    $moves = $sale->stockOperation->transactions()->with('inventory.batch')->orderBy('line_number')->orderBy('allocation_number')->get();
    workflowAssert($moves->map(fn ($move) => [$move->line_number, $move->allocation_number, $move->inventory->batch->product_id,
        $move->quantity, $move->input_quantity, $move->unit, $move->conversion_factor])->all() === [
            [1, 1, $products['A']->id, -24, 30, 'piece', 1], [1, 2, $products['A']->id, -6, 30, 'piece', 1],
            [2, 1, $products['B']->id, -6, 1, 'carton', 6]], 'mysql_real_purchase_receipt_multiline_sale_fefo_mapping');
    workflowAssert($purchasing->receive($actor, $purchase, $receiptData)->stock_operation_id === $purchase->stock_operation_id
        && $selling->confirm($actor, $sale, $confirmation)->stock_operation_id === $sale->stock_operation_id, 'full_workflow_replays_do_not_post_again');
    $salesFilters = ['from' => $today, 'to' => $today, 'customer_id' => $customer->id];
    $purchaseFilters = ['from' => $today, 'to' => $today, 'supplier_id' => $supplier->id];
    workflowAssert($reports->financial($actor, 'sales', $salesFilters)['summary'] === ['count' => 1, 'amount' => '67.500036', 'pieces' => '36']
        && $reports->financial($actor, 'purchases', $purchaseFilters)['summary'] === ['count' => 1, 'amount' => '9.469136', 'pieces' => '60'], 'mysql_reports_do_not_multiply_split_allocations');
    $big = $purchasing->save($actor, ['key' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'order_date' => $today, 'notes' => $tag,
        'items' => [['product_id' => $products['C']->id, 'quantity' => 10000, 'unit' => 'piece', 'unit_price' => '0']]]);
    $purchasing->receive($actor, $big, ['key' => (string) Str::uuid(), 'version' => 1,
        'items' => [['item_id' => $big->items->first()->id, 'batch_number' => $tag.':batch:C']]]);
    for ($i = 0; $i < 10; $i++) {
        $largeSale = $selling->save($actor, ['key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'order_date' => $today, 'notes' => $tag,
            'items' => [['product_id' => $products['C']->id, 'quantity' => 1000, 'unit' => 'piece']]]);
        workflowAssert($largeSale->total_amount === '999999999999.999000', 'exact_large_document_'.$i);
        $selling->confirm($actor, $largeSale, ['key' => (string) Str::uuid(), 'version' => 1]);
    }
    $summary = $reports->financial($actor, 'sales', $salesFilters)['summary'];
    workflowAssert($summary === ['count' => 11, 'amount' => '10000000000067.490036', 'pieces' => '10036'], 'mysql_report_totals_exceed_integer_millionths_without_precision_loss');
    workflowAssert($reports->financial($actor, 'purchases', $purchaseFilters)['summary'] === ['count' => 2, 'amount' => '9.469136', 'pieces' => '10060'], 'mysql_purchase_report_uses_received_quantity_snapshots');
    $inventory = $reports->inventory($actor, ['q' => $tag, 'expiry_days' => 7]);
    workflowAssert($inventory['summary'] === ['products' => 3, 'low' => 2, 'near_batches' => 1, 'expired_batches' => 0], 'mysql_stock_alert_derived_queries');
    $failed = $selling->save($actor, ['key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'order_date' => $today, 'notes' => $tag,
        'items' => [['product_id' => $products['A']->id, 'quantity' => 19, 'unit' => 'piece']]]);
    try {
        $selling->confirm($actor, $failed, ['key' => (string) Str::uuid(), 'version' => 1]);
        throw new RuntimeException('Insufficient workflow sale was posted.');
    } catch (ValidationException) {
        workflowAssert($failed->fresh()->status === 'draft' && $reports->financial($actor, 'sales', $salesFilters)['summary'] === $summary,
            'mysql_failed_oversell_does_not_change_reports');
    }
} finally {
    if ($fixtures) {
        DB::transaction(function () use ($tag, $fixtures) {
            ['role' => $role, 'actor' => $actor, 'category' => $category, 'customer' => $customer, 'supplier' => $supplier, 'products' => $products] = $fixtures;
            Role::where('code', 'admin')->lockForUpdate()->firstOrFail();
            $main = Warehouse::where('code', 'main')->lockForUpdate()->firstOrFail();
            $productIds = collect($products)->pluck('id');
            if (! User::whereKey($actor->id)->where('email', $tag.'@example.test')->exists()
                || ! Role::whereKey($role->id)->where('code', $tag)->exists() || $role->users()->where('id', '!=', $actor->id)->exists()
                || ! Category::whereKey($category->id)->where('name', $tag)->exists()
                || ! Customer::whereKey($customer->id)->where('name', $tag)->exists() || ! Supplier::whereKey($supplier->id)->where('name', $tag)->exists()
                || Product::whereIn('id', $productIds)->get()->contains(fn ($product) => ! str_starts_with($product->name, $tag.':'))
                || Product::whereIn('id', $productIds)->count() !== 3) {
                throw new RuntimeException('Workflow fixture identity changed; cleanup stopped.');
            }
            $purchases = PurchaseOrder::where('supplier_id', $supplier->id)->get();
            $sales = SalesOrder::where('customer_id', $customer->id)->get();
            $batches = ProductBatch::whereIn('product_id', $productIds)->get();
            $inventoryIds = Inventory::whereIn('batch_id', $batches->pluck('id'))->pluck('id');
            $operationIds = InventoryTransaction::whereIn('inventory_id', $inventoryIds)->pluck('stock_operation_id')->unique();
            $references = [...$purchases->map(fn ($order) => 'PO:'.$order->id)->all(), ...$sales->map(fn ($order) => 'SO:'.$order->id)->all()];
            if ($purchases->contains(fn ($order) => $order->user_id !== $actor->id || $order->notes !== $tag)
                || $sales->contains(fn ($order) => $order->user_id !== $actor->id || $order->notes !== $tag)
                || $batches->contains(fn ($batch) => ! str_starts_with($batch->batch_number, $tag.':batch:'))
                || StockOperation::whereIn('id', $operationIds)->get()->contains(fn ($op) => $op->user_id !== $actor->id || ! in_array($op->reference, $references, true))
                || DB::table('purchase_order_items')->whereIn('product_id', $productIds)->whereNotIn('purchase_order_id', $purchases->pluck('id'))->exists()
                || DB::table('purchase_order_items')->whereIn('purchase_order_id', $purchases->pluck('id'))->whereNotIn('product_id', $productIds)->exists()
                || DB::table('sales_order_items')->whereIn('product_id', $productIds)->whereNotIn('sales_order_id', $sales->pluck('id'))->exists()
                || DB::table('sales_order_items')->whereIn('sales_order_id', $sales->pluck('id'))->whereNotIn('product_id', $productIds)->exists()
                || CustomerProductPrice::where('customer_id', $customer->id)->whereNotIn('product_id', $productIds)->exists()
                || CustomerProductPrice::whereIn('product_id', $productIds)->where('customer_id', '!=', $customer->id)->exists()
                || Inventory::whereIn('id', $inventoryIds)->where('warehouse_id', '!=', $main->id)->exists()
                || InventoryTransaction::whereIn('stock_operation_id', $operationIds)->whereNotIn('inventory_id', $inventoryIds)->exists()
                || StockOperation::whereIn('reversal_of_id', $operationIds)->exists()) {
                throw new RuntimeException('Non-fixture workflow reference detected; cleanup stopped.');
            }
            DB::table('sales_order_items')->whereIn('sales_order_id', $sales->pluck('id'))->delete();
            DB::table('sales_orders')->whereIn('id', $sales->pluck('id'))->delete();
            DB::table('purchase_order_items')->whereIn('purchase_order_id', $purchases->pluck('id'))->delete();
            DB::table('purchase_orders')->whereIn('id', $purchases->pluck('id'))->delete();
            DB::table('customer_product_prices')->where('customer_id', $customer->id)->whereIn('product_id', $productIds)->delete();
            DB::table('inventory_transactions')->whereIn('stock_operation_id', $operationIds)->delete();
            DB::table('stock_operations')->whereIn('id', $operationIds)->delete();
            DB::table('inventory')->whereIn('id', $inventoryIds)->delete();
            DB::table('product_batches')->whereIn('id', $batches->pluck('id'))->delete();
            DB::table('products')->whereIn('id', $productIds)->delete();
            DB::table('categories')->where('id', $category->id)->where('name', $tag)->delete();
            DB::table('customers')->where('id', $customer->id)->where('name', $tag)->delete();
            DB::table('suppliers')->where('id', $supplier->id)->where('name', $tag)->delete();
            DB::table('users')->where('id', $actor->id)->where('email', $tag.'@example.test')->delete();
            DB::table('roles')->where('id', $role->id)->where('code', $tag)->delete();
        });
        echo "WORKFLOW_REPORT_FIXTURES_CLEANED_USER_DATA_PRESERVED\n";
    }
}
