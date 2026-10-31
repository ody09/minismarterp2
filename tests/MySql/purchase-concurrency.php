<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StockOperation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseOrders;
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
    throw new RuntimeException('Purchase verification requires local/testing MySQL.');
}
$directory = dirname(__DIR__, 2).'/.cache/mysql-purchase-tests';
if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}
$tag = '__phase4_verify_'.bin2hex(random_bytes(10));
$role = $actor = $supplier = $category = $product = null;

function purchaseRace(array $jobs): array
{
    global $directory, $tag;
    $path = $directory.'/'.$tag.'-'.bin2hex(random_bytes(5));
    $processes = [];
    try {
        foreach ($jobs as $index => $job) {
            $input = $path.'.'.$index.'.json';
            $output = $path.'.'.$index.'.result';
            file_put_contents($input, json_encode(['start' => $path.'.start', ...$job], JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, __DIR__.'/purchase-worker.php', $input, $output],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Could not launch purchase verification worker.');
            }
            fclose($pipes[0]);
            $processes[] = compact('process', 'pipes', 'output');
        }
        $deadline = microtime(true) + 25;
        while (count(array_filter($processes, fn ($item) => is_file($item['output'].'.ready'))) !== count($jobs)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Purchase workers failed to reach start barrier.');
            }
            usleep(10000);
        }
        file_put_contents($path.'.start', 'start');
        $results = [];
        foreach ($processes as $item) {
            while (proc_get_status($item['process'])['running']) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Purchase worker timed out.');
                }
                usleep(10000);
            }
            if (stream_get_contents($item['pipes'][2]) !== '' || ! is_file($item['output'])) {
                throw new RuntimeException('Purchase worker failed before producing a clean result.');
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

function purchaseAssert(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAIL '.$label);
    }
    echo 'PASS '.$label."\n";
}

try {
    $role = Role::create(['name' => $tag, 'code' => $tag]);
    $role->permissions()->sync(Permission::whereIn('code', ['purchases.manage', 'purchases.receive', 'inventory.post'])->pluck('id'));
    $actor = new User;
    $actor->forceFill(['name' => $tag, 'email' => $tag.'@example.test', 'password' => 'A9a'.bin2hex(random_bytes(20)),
        'role_id' => $role->id, 'is_active' => true])->save();
    $actor->refresh();
    $supplier = Supplier::create(['name' => $tag, 'is_active' => true]);
    $category = Category::create(['name' => $tag, 'is_active' => true]);
    $product = Product::create(['name' => $tag, 'category_id' => $category->id, 'pieces_per_carton' => 24,
        'purchase_unit' => 'carton', 'selling_unit' => 'piece', 'purchase_price' => '12.345678', 'selling_price' => '1', 'reorder_level' => 0, 'is_active' => true]);
    $service = app(PurchaseOrders::class);
    $draftData = fn () => ['key' => (string) Str::uuid(), 'supplier_id' => $supplier->id,
        'order_date' => CarbonImmutable::now(config('inventory.timezone'))->toDateString(), 'notes' => $tag, 'items' => [
            ['product_id' => $product->id, 'quantity' => 3, 'unit' => 'carton', 'unit_price' => '12.345678'],
            ['product_id' => $product->id, 'quantity' => 2, 'unit' => 'piece', 'unit_price' => '0.100001']]];
    $new = fn () => $service->save($actor, $draftData());
    $receipt = fn ($order) => ['key' => (string) Str::uuid(), 'version' => $order->version,
        'items' => $order->items->map(fn ($item) => ['item_id' => $item->id, 'batch_number' => $tag.':'.$order->id.':'.$item->line_number])->all()];
    $job = fn ($order, $method, $data) => ['actor' => $actor->id, 'order' => $order->id, 'method' => $method, 'data' => $data];
    $singleWinner = function (array $results): bool {
        $states = array_column($results, 'result');
        sort($states);

        return $states === ['completed', 'rejected'];
    };
    $checkOrder = function (PurchaseOrder $order): bool {
        $fresh = $order->fresh();
        $sum = (int) InventoryTransaction::where('stock_operation_id', $fresh->stock_operation_id)->sum('quantity');

        return $fresh->status === 'received' ? $sum === 74 && $fresh->items()->whereNull('batch_id')->count() === 0
            : $fresh->stock_operation_id === null && $sum === 0;
    };

    $order = $new();
    purchaseAssert($order->fresh()->total_amount === '37.237036', 'mysql_exact_six_decimal_purchase_total');
    $results = purchaseRace([$job($order, 'receive', $receipt($order)), $job($order, 'receive', $receipt($order))]);
    purchaseAssert($singleWinner($results) && $checkOrder($order), 'competing_receipt_keys_credit_once');
    $order = $new();
    $data = $receipt($order);
    $results = purchaseRace([$job($order, 'receive', $data), $job($order, 'receive', $data)]);
    purchaseAssert(array_column($results, 'result') === ['completed', 'completed']
        && $results[0]['operation'] === $results[1]['operation'] && $checkOrder($order), 'identical_receipt_replay_one_operation');

    foreach (['cancel', 'save'] as $method) {
        $order = $new();
        $change = $method === 'save' ? [...$draftData(), 'version' => 1, 'notes' => $tag.':edited'] : ['version' => 1];
        $results = purchaseRace([$job($order, 'receive', $receipt($order)), $job($order, $method, $change)]);
        purchaseAssert($singleWinner($results) && $checkOrder($order) && $order->fresh()->version === 2, 'receive_racing_'.$method.'_is_atomic');
    }

    $snapshot = function (string $label, array $worker, callable $action, callable $expected) {
        DB::beginTransaction();
        try {
            DB::table('purchase_orders')->get(); // Establish an older consistent-read snapshot.
            $result = purchaseRace([$worker])[0];
            if ($result['result'] !== 'completed') {
                throw new RuntimeException('Snapshot worker did not commit: '.json_encode($result));
            }
            $value = $error = null;
            try {
                $value = $action($result);
            } catch (Throwable $exception) {
                $error = $exception;
            }
            purchaseAssert($expected($value, $error, $result), $label);
        } finally {
            DB::rollBack();
        }
    };
    $order = $new();
    $snapshot('nested_snapshot_can_receive_draft_created_after_snapshot', $job($order, 'create', $draftData()),
        function ($worker) use ($service, $actor, $receipt) {
            $current = PurchaseOrder::whereKey($worker['id'])->lockForUpdate()->firstOrFail();
            $current->setRelation('items', $current->items()->lockForUpdate()->get());

            return $service->receive($actor, $current, $receipt($current));
        }, fn ($value, $error) => ! $error && $value->status === 'received' && $value->items->every(fn ($item) => $item->batch_id !== null));
    $order = $new();
    $data = $receipt($order);
    $snapshot('nested_snapshot_receipt_replay_uses_current_document_and_items', $job($order, 'receive', $data),
        fn () => $service->receive($actor, $order, $data),
        fn ($value, $error, $worker) => ! $error && $value->status === 'received' && $value->stock_operation_id === $worker['operation']
            && $value->items->every(fn ($item) => $item->batch_id !== null));
    $order = $new();
    $data = $receipt($order);
    $snapshot('nested_snapshot_stale_receipt_rejected_after_draft_edit', $job($order, 'save', [...$draftData(), 'version' => 1]),
        fn () => $service->receive($actor, $order, $data), fn ($value, $error) => $error instanceof ValidationException);
    foreach (['purchases.receive', 'inventory.post'] as $permission) {
        $order = $new();
        $snapshot('nested_current_permission_'.$permission, [...$job($order, 'revoke', []), 'permission' => $permission],
            fn () => $service->receive($actor, $order, $receipt($order)), fn ($value, $error) => $error instanceof AuthorizationException);
        $role->permissions()->syncWithoutDetaching(Permission::where('code', $permission)->pluck('id'));
    }
    $order = $new();
    $snapshot('nested_current_actor_revocation', $job($order, 'disable', []),
        fn () => $service->receive($actor, $order, $receipt($order)), fn ($value, $error) => $error instanceof AuthorizationException);
    $actor->refresh()->forceFill(['is_active' => true])->save();
    $actor->refresh();

    $order = $new();
    $count = 0;
    Event::listen('eloquent.creating: '.InventoryTransaction::class, function () use (&$count) {
        if (++$count === 2) {
            throw new RuntimeException('purchase-fixture-second-movement');
        }
    });
    try {
        $service->receive($actor, $order, $receipt($order));
        throw new RuntimeException('Missing purchase rollback.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'purchase-fixture-second-movement') {
            throw $exception;
        }
    } finally {
        Event::forget('eloquent.creating: '.InventoryTransaction::class);
    }
    purchaseAssert($order->fresh()->status === 'draft' && $order->fresh()->stock_operation_id === null
        && ! ProductBatch::where('batch_number', 'like', $tag.':'.$order->id.':%')->exists(), 'mysql_failure_rolls_back_document_new_batches_and_stock');
    try {
        DB::table('suppliers')->where('id', $supplier->id)->where('name', $tag)->delete();
        throw new RuntimeException('Missing purchase supplier foreign key.');
    } catch (QueryException $exception) {
        if ((string) $exception->getCode() !== '23000') {
            throw $exception;
        }
        echo "PASS mysql_purchase_foreign_keys_restrict_history_deletion\n";
    }
} finally {
    if ($actor && $role && $product && $category && $supplier) {
        DB::transaction(function () use ($tag, $actor, $role, $product, $category, $supplier) {
            Role::where('code', 'admin')->lockForUpdate()->firstOrFail();
            Warehouse::where('code', 'main')->lockForUpdate()->firstOrFail();
            if (! User::whereKey($actor->id)->where('email', $tag.'@example.test')->exists()
                || ! Role::whereKey($role->id)->where('code', $tag)->exists() || $role->users()->where('id', '!=', $actor->id)->exists()
                || ! Product::whereKey($product->id)->where('name', $tag)->exists()
                || ! Category::whereKey($category->id)->where('name', $tag)->exists()
                || ! Supplier::whereKey($supplier->id)->where('name', $tag)->exists()) {
                throw new RuntimeException('Fixture ownership changed; cleanup stopped.');
            }
            $orders = PurchaseOrder::where('supplier_id', $supplier->id)->get();
            $batches = ProductBatch::where('product_id', $product->id)->get();
            $inventoryIds = Inventory::whereIn('batch_id', $batches->pluck('id'))->pluck('id');
            $operationIds = $orders->pluck('stock_operation_id')->filter()->values();
            if ($orders->contains(fn ($order) => $order->user_id !== $actor->id || ! str_starts_with($order->notes ?? '', $tag))
                || $batches->contains(fn ($batch) => ! str_starts_with($batch->batch_number, $tag.':'))
                || DB::table('purchase_order_items')->whereIn('purchase_order_id', $orders->pluck('id'))->where('product_id', '!=', $product->id)->exists()
                || DB::table('purchase_order_items')->where('product_id', $product->id)->whereNotIn('purchase_order_id', $orders->pluck('id'))->exists()
                || InventoryTransaction::whereIn('inventory_id', $inventoryIds)->whereNotIn('stock_operation_id', $operationIds)->exists()
                || InventoryTransaction::whereIn('stock_operation_id', $operationIds)->whereNotIn('inventory_id', $inventoryIds)->exists()
                || StockOperation::whereIn('id', $operationIds)->where('user_id', '!=', $actor->id)->exists()
                || StockOperation::whereIn('reversal_of_id', $operationIds)->exists()) {
                throw new RuntimeException('Non-fixture references detected; cleanup stopped.');
            }
            DB::table('purchase_order_items')->whereIn('purchase_order_id', $orders->pluck('id'))->delete();
            DB::table('purchase_orders')->whereIn('id', $orders->pluck('id'))->delete();
            DB::table('inventory_transactions')->whereIn('stock_operation_id', $operationIds)->delete();
            DB::table('stock_operations')->whereIn('id', $operationIds)->delete();
            DB::table('inventory')->whereIn('id', $inventoryIds)->delete();
            DB::table('product_batches')->whereIn('id', $batches->pluck('id'))->delete();
            DB::table('products')->where('id', $product->id)->where('name', $tag)->delete();
            DB::table('categories')->where('id', $category->id)->where('name', $tag)->delete();
            DB::table('suppliers')->where('id', $supplier->id)->where('name', $tag)->delete();
            DB::table('users')->where('id', $actor->id)->where('email', $tag.'@example.test')->delete();
            DB::table('roles')->where('id', $role->id)->where('code', $tag)->delete();
        });
        echo "PURCHASE_FIXTURES_CLEANED_USER_DATA_PRESERVED\n";
    }
}
