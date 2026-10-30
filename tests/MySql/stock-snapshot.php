<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\StockOperation;
use App\Models\User;
use App\Services\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('Stock verification is restricted to local/testing environments.');
}
$artifactDirectory = dirname(__DIR__, 2).'/.cache/mysql-stock-tests';
if (! is_dir($artifactDirectory)) {
    mkdir($artifactDirectory, 0777, true);
}

if (DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('This verification requires MySQL.');
}
$tag = '__phase3_snapshot_'.bin2hex(random_bytes(10));
$role = $actor = $category = $product = null;
$failures = [];

function competingCommit(array $job, string $tag): array
{
    $path = dirname(__DIR__, 2).'/.cache/mysql-stock-tests/'.$tag.'-'.bin2hex(random_bytes(4));
    file_put_contents($path.'.start', 'start');
    file_put_contents($path.'.json', json_encode(['start' => $path.'.start', ...$job], JSON_THROW_ON_ERROR));
    $process = proc_open([PHP_BINARY, __DIR__.'/stock-worker.php', $path.'.json', $path.'.result'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start competing transaction.');
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if ($stderr !== '' || ! is_file($path.'.result')) {
        throw new RuntimeException('Competing transaction did not produce a clean result.');
    }

    return json_decode(file_get_contents($path.'.result'), true, flags: JSON_THROW_ON_ERROR);
}

function snapshotCase(string $label, array $job, callable $action, callable $expected, string $tag, array &$failures): void
{
    DB::beginTransaction();
    try {
        // An InnoDB consistent read establishes the enclosing transaction snapshot
        // before the independent worker commits its stock or authorization change.
        DB::table('inventory')->get();
        $worker = competingCommit($job, $tag);
        if (! in_array($worker['result'], ['posted', 'mutated'], true)) {
            throw new RuntimeException('Competing write failed: '.json_encode($worker));
        }
        $error = null;
        $result = null;
        try {
            $result = $action();
        } catch (Throwable $exception) {
            $error = $exception;
        }
        if ($expected($result, $error, $worker)) {
            echo 'PASS '.$label."\n";
        } else {
            $failures[] = $label;
            echo 'FAIL '.$label.' outcome='.($error ? get_class($error) : 'accepted')."\n";
        }
    } finally {
        DB::rollBack();
    }
}

try {
    $role = Role::create(['name' => $tag, 'code' => $tag]);
    $role->permissions()->sync(Permission::whereIn('code', ['inventory.post', 'inventory.reverse'])->pluck('id'));
    $actor = new User;
    $actor->forceFill(['name' => $tag, 'email' => $tag.'@example.test', 'password' => 'A9a'.bin2hex(random_bytes(16)),
        'role_id' => $role->id, 'is_active' => true])->save();
    $actor->refresh();
    $category = Category::create(['name' => $tag, 'is_active' => true]);
    $product = Product::create(['name' => $tag, 'category_id' => $category->id, 'pieces_per_carton' => 24,
        'purchase_unit' => 'carton', 'selling_unit' => 'piece', 'purchase_price' => '12', 'selling_price' => '1', 'reorder_level' => 0, 'is_active' => true]);
    $batch = ProductBatch::create(['product_id' => $product->id, 'batch_number' => $tag,
        'expiration_date' => CarbonImmutable::now(config('inventory.timezone'))->addMonth()->toDateString()]);
    $ledger = app(StockLedger::class);
    $ledger->receive($actor, (string) Str::uuid(), $tag.':initial', [['batch_id' => $batch->id, 'quantity' => 10, 'unit' => 'piece']]);
    $key = (string) Str::uuid();
    $line = [['batch_id' => $batch->id, 'quantity' => 1, 'unit' => 'piece']];
    snapshotCase('nested_replay_reads_committed_operation', ['actor' => $actor->id, 'method' => 'receive', 'key' => $key, 'reference' => $tag.':replay', 'lines' => $line],
        fn () => $ledger->receive($actor, $key, $tag.':replay', $line),
        fn ($result, $error, $worker) => ! $error && $result->id === $worker['id'] && $result->transactions->count() === 1
            && $result->transactions->first()->quantity === 1, $tag, $failures);

    $line = [['batch_id' => $batch->id, 'quantity' => 1, 'unit' => 'piece']];
    $denied = fn ($result, $error) => $error instanceof AuthorizationException || ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 403);
    snapshotCase('nested_actor_revocation_is_current', ['actor' => $actor->id, 'method' => 'disable_fixture_actor'],
        fn () => $ledger->receive($actor, (string) Str::uuid(), $tag.':disabled', $line), $denied, $tag, $failures);
    $actor->refresh()->forceFill(['is_active' => true])->save();
    $actor->refresh();
    snapshotCase('nested_permission_revocation_is_current', ['actor' => $actor->id, 'method' => 'revoke_fixture_permission'],
        fn () => $ledger->receive($actor, (string) Str::uuid(), $tag.':revoked', $line), $denied, $tag, $failures);
    $role->permissions()->syncWithoutDetaching(Permission::where('code', 'inventory.post')->pluck('id'));

    $extra = ProductBatch::create(['product_id' => $product->id, 'batch_number' => $tag.':other']);
    $target = ProductBatch::create(['product_id' => $product->id, 'batch_number' => $tag.':target']);
    $bound = $ledger->receive($actor, (string) Str::uuid(), $tag.':bound', [['batch_id' => $extra->id, 'quantity' => 1, 'unit' => 'piece']]);
    // Simulate a valid large cumulative history on tagged fixture rows only.
    $large = PHP_INT_MAX - 16; // prior real fixture balance is 11; total becomes MAX-5.
    DB::table('inventory')->where('batch_id', $extra->id)->update(['quantity' => $large]);
    DB::table('inventory_transactions')->where('stock_operation_id', $bound->id)->update(['quantity' => $large, 'input_quantity' => $large]);
    snapshotCase('nested_product_total_uses_current_rows', ['actor' => $actor->id, 'method' => 'receive', 'key' => (string) Str::uuid(), 'reference' => $tag.':plus4',
        'lines' => [['batch_id' => $target->id, 'quantity' => 4, 'unit' => 'piece']]],
        fn () => $ledger->receive($actor, (string) Str::uuid(), $tag.':plus2', [['batch_id' => $target->id, 'quantity' => 2, 'unit' => 'piece']]),
        fn ($result, $error) => $error instanceof ValidationException, $tag, $failures);
} finally {
    if ($product && $category && $actor && $role) {
        DB::transaction(function () use ($tag, $product, $category, $actor, $role) {
            if (! Product::whereKey($product->id)->where('name', $tag)->exists() || ! User::whereKey($actor->id)->where('email', $tag.'@example.test')->exists()
                || ! Role::whereKey($role->id)->where('code', $tag)->exists() || $role->users()->where('id', '!=', $actor->id)->exists()) {
                throw new RuntimeException('Snapshot fixture identity changed; cleanup stopped.');
            }
            $batches = ProductBatch::where('product_id', $product->id)->get();
            if ($batches->contains(fn ($batch) => ! str_starts_with($batch->batch_number, $tag))) {
                throw new RuntimeException('Non-fixture batch found; cleanup stopped.');
            }
            $inventoryIds = Inventory::whereIn('batch_id', $batches->pluck('id'))->pluck('id');
            $operationIds = InventoryTransaction::whereIn('inventory_id', $inventoryIds)->pluck('stock_operation_id')->unique();
            $operations = StockOperation::whereIn('id', $operationIds)->get();
            if ($operations->contains(fn ($operation) => ! str_starts_with($operation->reference, $tag.':') || $operation->user_id !== $actor->id)
                || InventoryTransaction::whereIn('stock_operation_id', $operationIds)->whereNotIn('inventory_id', $inventoryIds)->exists()) {
                throw new RuntimeException('Non-fixture source found; cleanup stopped.');
            }
            DB::table('inventory_transactions')->whereIn('inventory_id', $inventoryIds)->delete();
            DB::table('stock_operations')->whereIn('id', $operationIds)->delete();
            DB::table('inventory')->whereIn('id', $inventoryIds)->delete();
            DB::table('product_batches')->whereIn('id', $batches->pluck('id'))->delete();
            DB::table('products')->where('id', $product->id)->where('name', $tag)->delete();
            DB::table('categories')->where('id', $category->id)->where('name', $tag)->delete();
            DB::table('users')->where('id', $actor->id)->where('email', $tag.'@example.test')->delete();
            DB::table('roles')->where('id', $role->id)->where('code', $tag)->delete();
        });
        echo "SNAPSHOT_FIXTURES_CLEANED_USER_DATA_PRESERVED\n";
    }
}
exit($failures ? 1 : 0);
