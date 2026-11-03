<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockOperation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesOrders;
use App\Services\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing']) || DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('Sales verification requires local/testing MySQL.');
}
$directory = dirname(__DIR__, 2).'/.cache/mysql-sales-tests';
if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}
$tag = '__phase5_verify_'.bin2hex(random_bytes(10));
$role = $actor = $customer = $category = $product = null;

function salesRace(array $jobs): array
{
    global $directory, $tag;
    $path = $directory.'/'.$tag.'-'.bin2hex(random_bytes(5));
    $processes = [];
    try {
        foreach ($jobs as $index => $job) {
            $input = $path.'.'.$index.'.json';
            $output = $path.'.'.$index.'.result';
            file_put_contents($input, json_encode(['start' => $path.'.start', ...$job], JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, __DIR__.'/sales-worker.php', $input, $output],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Could not launch sales worker.');
            }
            fclose($pipes[0]);
            $processes[] = compact('process', 'pipes', 'output');
        }
        $deadline = microtime(true) + 25;
        while (count(array_filter($processes, fn ($item) => is_file($item['output'].'.ready'))) !== count($jobs)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Sales workers did not reach barrier.');
            }
            usleep(10000);
        }
        file_put_contents($path.'.start', 'start');
        $results = [];
        foreach ($processes as $item) {
            while (proc_get_status($item['process'])['running']) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Sales worker timed out.');
                }
                usleep(10000);
            }
            if (stream_get_contents($item['pipes'][2]) !== '' || ! is_file($item['output'])) {
                throw new RuntimeException('Sales worker did not return a clean result.');
            }
            $results[] = json_decode(file_get_contents($item['output']), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($processes as $item) {
            if (proc_get_status($item['process'])['running']) {
                proc_terminate($item['process']);
            }
            fclose($item['pipes'][1]);
            fclose($item['pipes'][2]);
            proc_close($item['process']);
        }
    }
}

function salesAssert(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAIL '.$label);
    }
    echo 'PASS '.$label."\n";
}

try {
    [$role, $actor, $customer, $category, $product] = DB::transaction(function () use ($tag) {
        $role = Role::create(['name' => $tag, 'code' => $tag]);
        $role->permissions()->sync(Permission::whereIn('code', ['sales.manage', 'sales.post', 'prices.manage', 'inventory.post'])->pluck('id'));
        $actor = new User;
        $actor->forceFill(['name' => $tag, 'email' => $tag.'@example.test', 'password' => 'A9a'.bin2hex(random_bytes(20)),
            'role_id' => $role->id, 'is_active' => true])->save();
        $customer = Customer::create(['name' => $tag, 'is_active' => true]);
        $category = Category::create(['name' => $tag, 'is_active' => true]);
        $product = Product::create(['name' => $tag, 'category_id' => $category->id, 'pieces_per_carton' => 24,
            'purchase_unit' => 'carton', 'selling_unit' => 'piece', 'purchase_price' => '24', 'selling_price' => '2.500001', 'reorder_level' => 0, 'is_active' => true]);

        return [$role, $actor->refresh(), $customer, $category, $product];
    });
    $service = app(SalesOrders::class);
    $today = CarbonImmutable::now(config('inventory.timezone'))->toDateString();
    $draftData = fn ($quantity = 7) => ['key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'order_date' => $today,
        'notes' => $tag, 'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit' => 'piece']]];
    $new = fn ($quantity = 7) => $service->save($actor, $draftData($quantity));
    $confirmation = fn ($order) => ['key' => (string) Str::uuid(), 'version' => $order->version];
    $job = fn ($order, $method, $data) => ['actor' => $actor->id, 'customer' => $customer->id, 'product' => $product->id,
        'order' => $order?->id, 'method' => $method, 'data' => $data];
    $stock = function (int $quantity, int $days = 1) use ($actor, $product, $tag) {
        $batch = ProductBatch::create(['product_id' => $product->id, 'batch_number' => $tag.':'.Str::uuid(),
            'expiration_date' => CarbonImmutable::now(config('inventory.timezone'))->addDays($days)->toDateString()]);
        app(StockLedger::class)->receive($actor, (string) Str::uuid(), $tag.':receipt:'.Str::uuid(),
            [['batch_id' => $batch->id, 'quantity' => $quantity, 'unit' => 'piece']]);

        return $batch;
    };
    $balance = fn () => (int) Inventory::join('product_batches', 'product_batches.id', '=', 'inventory.batch_id')->where('product_batches.product_id', $product->id)->sum('inventory.quantity');
    $winner = function ($results) {
        $states = array_column($results, 'result');
        sort($states);

        return $states === ['completed', 'rejected'];
    };
    $early = $stock(4);
    $later = $stock(6, 7);
    $one = $new();
    $two = $new();
    salesAssert($one->fresh()->total_amount === '17.500007', 'mysql_exact_sales_decimal_total');
    $results = salesRace([$job($one, 'confirm', $confirmation($one)), $job($two, 'confirm', $confirmation($two))]);
    salesAssert($winner($results) && $balance() === 3, 'competing_sales_cannot_oversell');
    $posted = collect($results)->firstWhere('result', 'completed');
    $moves = InventoryTransaction::where('stock_operation_id', $posted['operation'])->with('inventory')->orderBy('allocation_number')->get();
    salesAssert($moves->pluck('quantity')->all() === [-4, -3]
        && $moves->map(fn ($move) => $move->inventory->batch_id)->all() === [$early->id, $later->id], 'mysql_sale_fefo_allocations_link_to_line');
    $stock(100, 30);
    foreach ([true, false] as $sameKey) {
        $order = $new(2);
        $before = $balance();
        $data = $confirmation($order);
        $results = salesRace([$job($order, 'confirm', $data), $job($order, 'confirm', $sameKey ? $data : $confirmation($order))]);
        salesAssert(($sameKey ? array_column($results, 'result') === ['completed', 'completed'] && $results[0]['operation'] === $results[1]['operation'] : $winner($results))
            && $balance() === $before - 2, $sameKey ? 'same_key_confirms_once' : 'different_keys_confirm_once');
    }
    foreach (['cancel', 'save'] as $method) {
        $order = $new(1);
        $before = $balance();
        $change = $method === 'save' ? [...$draftData(1), 'version' => 1, 'notes' => $tag.':edited'] : ['version' => 1];
        $results = salesRace([$job($order, 'confirm', $confirmation($order)), $job($order, $method, $change)]);
        $fresh = $order->fresh();
        salesAssert($winner($results) && $fresh->version === 2
            && $balance() === $before - ($fresh->status === 'confirmed' ? 1 : 0), 'confirm_racing_'.$method.'_is_atomic');
    }
    $snapshot = function (string $label, array $worker, callable $action, callable $expected) {
        DB::beginTransaction();
        try {
            DB::table('sales_orders')->get();
            $result = salesRace([$worker])[0];
            if ($result['result'] !== 'completed') {
                throw new RuntimeException('Snapshot worker failed: '.json_encode($result));
            }
            $value = $error = null;
            try {
                $value = $action($result);
            } catch (Throwable $exception) {
                $error = $exception;
            }
            salesAssert($expected($value, $error, $result), $label);
        } finally {
            DB::rollBack();
        }
    };
    $order = $new(1);
    $snapshot('old_snapshot_can_confirm_newly_created_draft', $job(null, 'create', $draftData(1)),
        function ($worker) use ($service, $actor, $confirmation) {
            $current = SalesOrder::whereKey($worker['id'])->lockForUpdate()->firstOrFail();

            return $service->confirm($actor, $current, $confirmation($current));
        }, fn ($value, $error) => ! $error && $value->status === 'confirmed' && $value->items->count() === 1);
    $data = $confirmation($order);
    $snapshot('old_snapshot_replay_returns_current_document_items_and_operation', $job($order, 'confirm', $data),
        fn () => $service->confirm($actor, $order, $data),
        fn ($value, $error, $worker) => ! $error && $value->status === 'confirmed' && $value->stockOperation->id === $worker['operation'] && $value->items->count() === 1);
    $order = $new(1);
    $snapshot('old_snapshot_stale_edited_sale_rejected', $job($order, 'save', [...$draftData(1), 'version' => 1]),
        fn () => $service->confirm($actor, $order, $confirmation($order)), fn ($value, $error) => $error instanceof ValidationException);
    $order = $new(1);
    $snapshot('old_snapshot_sees_current_catalog_price', $job($order, 'catalog', []),
        fn () => $service->confirm($actor, $order, $confirmation($order)), fn ($value, $error) => $error instanceof ValidationException);
    $product->refresh()->update(['selling_price' => '2.500001']);
    $priceData = ['customer_id' => $customer->id, 'product_id' => $product->id, 'unit' => 'piece',
        'min_quantity' => 1000, 'unit_price' => '1.250001', 'valid_from' => null, 'valid_to' => null, 'is_active' => true];
    $results = salesRace([$job(null, 'price', $priceData), $job(null, 'price', $priceData)]);
    salesAssert($winner($results), 'parallel_overlapping_price_rules_have_one_winner');
    $order = $new(1);
    $snapshot('old_snapshot_sees_new_customer_price', $job(null, 'price', [...$priceData, 'min_quantity' => 1]),
        fn () => $service->confirm($actor, $order, $confirmation($order)), fn ($value, $error) => $error instanceof ValidationException);
    $rule = CustomerProductPrice::where('customer_id', $customer->id)->where('min_quantity', 1)->firstOrFail();
    $order = $new(1);
    $snapshot('old_snapshot_sees_edited_price_rule_version', [...$job(null, 'price', [...$priceData, 'min_quantity' => 1, 'version' => 1]), 'price_id' => $rule->id],
        fn () => $service->confirm($actor, $order, $confirmation($order)), fn ($value, $error) => $error instanceof ValidationException);
    foreach (['sales.post', 'inventory.post'] as $permission) {
        $order = $new(1);
        $snapshot('old_snapshot_current_permission_'.$permission, [...$job($order, 'revoke', []), 'permission' => $permission],
            fn () => $service->confirm($actor, $order, $confirmation($order)), fn ($value, $error) => $error instanceof AuthorizationException);
        $role->permissions()->syncWithoutDetaching(Permission::where('code', $permission)->pluck('id'));
    }
    $order = $new(1);
    $snapshot('old_snapshot_current_actor_revocation', $job($order, 'disable', []),
        fn () => $service->confirm($actor, $order, $confirmation($order)), fn ($value, $error) => $error instanceof AuthorizationException);
    $actor->refresh()->forceFill(['is_active' => true])->save();
    $actor->refresh();
    $stock(1, 0);
    $stock(1, 0);
    $order = $new(2);
    $before = $balance();
    $count = 0;
    Event::listen('eloquent.creating: '.InventoryTransaction::class, function () use (&$count) {
        if (++$count === 2) {
            throw new RuntimeException('sales-fixture-second-allocation');
        }
    });
    try {
        $service->confirm($actor, $order, $confirmation($order));
        throw new RuntimeException('Missing sales rollback.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'sales-fixture-second-allocation') {
            throw $exception;
        }
    } finally {
        Event::forget('eloquent.creating: '.InventoryTransaction::class);
    }
    salesAssert($order->fresh()->status === 'draft' && $order->fresh()->stock_operation_id === null && $before === $balance(), 'mysql_failed_allocation_rolls_back_sale_and_balances');
    try {
        DB::table('customers')->where('id', $customer->id)->where('name', $tag)->delete();
        throw new RuntimeException('Missing customer foreign key.');
    } catch (QueryException $exception) {
        if ((string) $exception->getCode() !== '23000') {
            throw $exception;
        }
        echo "PASS mysql_sales_foreign_keys_retain_history\n";
    }
} finally {
    if ($actor && $role && $product && $category && $customer) {
        DB::transaction(function () use ($tag, $actor, $role, $product, $category, $customer) {
            Role::where('code', 'admin')->lockForUpdate()->firstOrFail();
            Warehouse::where('code', 'main')->lockForUpdate()->firstOrFail();
            if (! User::whereKey($actor->id)->where('email', $tag.'@example.test')->exists()
                || ! Role::whereKey($role->id)->where('code', $tag)->exists() || $role->users()->where('id', '!=', $actor->id)->exists()
                || ! Product::whereKey($product->id)->where('name', $tag)->exists()
                || ! Category::whereKey($category->id)->where('name', $tag)->exists()
                || ! Customer::whereKey($customer->id)->where('name', $tag)->exists()) {
                throw new RuntimeException('Fixture ownership changed; cleanup stopped.');
            }
            $orders = SalesOrder::where('customer_id', $customer->id)->get();
            $batches = ProductBatch::where('product_id', $product->id)->get();
            $inventoryIds = Inventory::whereIn('batch_id', $batches->pluck('id'))->pluck('id');
            $operationIds = InventoryTransaction::whereIn('inventory_id', $inventoryIds)->pluck('stock_operation_id')->unique();
            $references = $orders->map(fn ($order) => 'SO:'.$order->id)->all();
            $operations = StockOperation::whereIn('id', $operationIds)->get();
            if ($orders->contains(fn ($order) => $order->user_id !== $actor->id || ! str_starts_with($order->notes ?? '', $tag))
                || $batches->contains(fn ($batch) => ! str_starts_with($batch->batch_number, $tag.':'))
                || $operations->contains(fn ($operation) => $operation->user_id !== $actor->id || (! str_starts_with($operation->reference, $tag.':receipt:') && ! in_array($operation->reference, $references, true)))
                || DB::table('sales_order_items')->whereIn('sales_order_id', $orders->pluck('id'))->where('product_id', '!=', $product->id)->exists()
                || DB::table('sales_order_items')->where('product_id', $product->id)->whereNotIn('sales_order_id', $orders->pluck('id'))->exists()
                || DB::table('purchase_order_items')->where('product_id', $product->id)->exists()
                || CustomerProductPrice::where('product_id', $product->id)->where('customer_id', '!=', $customer->id)->exists()
                || CustomerProductPrice::where('customer_id', $customer->id)->where('product_id', '!=', $product->id)->exists()
                || InventoryTransaction::whereIn('stock_operation_id', $operationIds)->whereNotIn('inventory_id', $inventoryIds)->exists()
                || StockOperation::whereIn('reversal_of_id', $operationIds)->exists()) {
                throw new RuntimeException('Non-fixture references detected; cleanup stopped.');
            }
            DB::table('sales_order_items')->whereIn('sales_order_id', $orders->pluck('id'))->delete();
            DB::table('sales_orders')->whereIn('id', $orders->pluck('id'))->delete();
            DB::table('customer_product_prices')->where('customer_id', $customer->id)->where('product_id', $product->id)->delete();
            DB::table('inventory_transactions')->whereIn('stock_operation_id', $operationIds)->delete();
            DB::table('stock_operations')->whereIn('id', $operationIds)->delete();
            DB::table('inventory')->whereIn('id', $inventoryIds)->delete();
            DB::table('product_batches')->whereIn('id', $batches->pluck('id'))->delete();
            DB::table('products')->where('id', $product->id)->where('name', $tag)->delete();
            DB::table('categories')->where('id', $category->id)->where('name', $tag)->delete();
            DB::table('customers')->where('id', $customer->id)->where('name', $tag)->delete();
            DB::table('users')->where('id', $actor->id)->where('email', $tag.'@example.test')->delete();
            DB::table('roles')->where('id', $role->id)->where('code', $tag)->delete();
        });
        echo "SALES_FIXTURES_CLEANED_USER_DATA_PRESERVED\n";
    }
}
