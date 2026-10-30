<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockOperation;
use App\Models\User;
use App\Services\StockBalances;
use App\Services\StockLedger;
use App\Services\StockMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
$tag = '__phase3_verify_'.bin2hex(random_bytes(10));
$actor = User::where('is_active', true)->whereHas('role', fn ($query) => $query->where('code', 'admin')->where('is_system', true))->firstOrFail();
$category = null;
$product = null;
$batch = null;

function race(array $jobs, string $tag): array
{
    $start = dirname(__DIR__, 2).'/.cache/mysql-stock-tests/'.$tag.'-'.bin2hex(random_bytes(4)).'.start';
    $processes = [];
    try {
        foreach ($jobs as $i => $job) {
            $input = $start.'.'.$i.'.json';
            $output = $start.'.'.$i.'.result';
            file_put_contents($input, json_encode(['start' => $start, ...$job], JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, __DIR__.'/stock-worker.php', $input, $output], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start verification worker.');
            }
            fclose($pipes[0]);
            $processes[] = ['process' => $process, 'pipes' => $pipes, 'output' => $output];
        }
        $deadline = microtime(true) + 20;
        while (count(array_filter($processes, fn ($item) => is_file($item['output'].'.ready'))) !== count($jobs)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Verification workers did not reach the barrier.');
            }
            usleep(10000);
        }
        file_put_contents($start, 'start');
        $results = [];
        foreach ($processes as $item) {
            while (proc_get_status($item['process'])['running']) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Concurrent stock verification timed out.');
                }
                usleep(10000);
            }
            $stderr = stream_get_contents($item['pipes'][2]);
            if ($stderr !== '' || ! is_file($item['output'])) {
                throw new RuntimeException('Verification worker failed before producing a result.');
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

try {
    // Only these random, explicitly tagged verification fixtures are committed so
    // independent MySQL connections can see them. The finally block removes only them.
    $category = Category::create(['name' => $tag, 'is_active' => true]);
    $product = Product::create(['name' => $tag, 'category_id' => $category->id, 'pieces_per_carton' => 24,
        'purchase_unit' => 'carton', 'selling_unit' => 'piece', 'purchase_price' => '12', 'selling_price' => '1', 'reorder_level' => 0, 'is_active' => true]);
    $batch = ProductBatch::create(['product_id' => $product->id, 'batch_number' => $tag,
        'expiration_date' => CarbonImmutable::now(config('inventory.timezone'))->addMonth()->toDateString()]);
    $ledger = app(StockLedger::class);
    $receipt = $ledger->receive($actor, (string) Str::uuid(), $tag.':initial', [['batch_id' => $batch->id, 'quantity' => 10, 'unit' => 'piece']]);
    $jobs = [];
    foreach ([1, 2] as $i) {
        $jobs[] = ['actor' => $actor->id, 'method' => 'issue', 'key' => (string) Str::uuid(), 'reference' => $tag.':sale'.$i,
            'lines' => [['product_id' => $product->id, 'quantity' => 7, 'unit' => 'piece']]];
    }
    $results = race($jobs, $tag);
    $states = array_column($results, 'result');
    sort($states);
    if ($states !== ['posted', 'rejected'] || Inventory::where('batch_id', $batch->id)->firstOrFail()->quantity !== 3) {
        throw new RuntimeException('Concurrent issues oversold or failed: '.json_encode($results));
    }
    echo "MYSQL_CONCURRENT_ISSUES_ONE_WINNER_BALANCE_3\n";
    $key = (string) Str::uuid();
    $job = ['actor' => $actor->id, 'method' => 'receive', 'key' => $key, 'reference' => $tag.':replay',
        'lines' => [['batch_id' => $batch->id, 'quantity' => 2, 'unit' => 'piece']]];
    $replays = race([$job, $job], $tag);
    if ($replays[0]['result'] !== 'posted' || $replays[1]['result'] !== 'posted' || $replays[0]['id'] !== $replays[1]['id']
        || Inventory::where('batch_id', $batch->id)->firstOrFail()->quantity !== 5 || StockOperation::where('idempotency_key', $key)->count() !== 1) {
        throw new RuntimeException('Concurrent replay duplicated the receipt.');
    }
    echo "MYSQL_CONCURRENT_REPLAY_ONE_OPERATION_ONE_CREDIT\n";
    $saleId = collect($results)->firstWhere('result', 'posted')['id'];
    $ledger->reverse($actor, (string) Str::uuid(), StockOperation::findOrFail($saleId), 'Verification reversal');
    $ledger->reverse($actor, (string) Str::uuid(), $receipt, 'Verification reversal');
    $summary = app(StockBalances::class)->products()->whereKey($product->id)->firstOrFail();
    if ((int) $summary->physical_quantity !== 2 || (int) $summary->sellable_quantity !== 2) {
        throw new RuntimeException('MySQL reversal/read-side totals did not match.');
    }
    try {
        app(StockMetadata::class)->updateProduct($product, ['pieces_per_carton' => 12]);
        throw new RuntimeException('Posted packing was not protected.');
    } catch (ValidationException) {
        echo "MYSQL_REVERSALS_TOTALS_AND_METADATA_GUARD_OK\n";
    }
} finally {
    // Validate ownership and cross-references before any fixture deletion. Never
    // delete real user rows or an operation involving a non-fixture product.
    if ($product && $batch && $category) {
        DB::transaction(function () use ($tag, $product, $batch, $category) {
            if (! Product::whereKey($product->id)->where('name', $tag)->exists()
                || ! ProductBatch::whereKey($batch->id)->where('batch_number', $tag)->exists()
                || ! Category::whereKey($category->id)->where('name', $tag)->exists()) {
                throw new RuntimeException('Fixture identity changed; cleanup stopped.');
            }
            $originalIds = StockOperation::where('reference', 'like', $tag.':%')->pluck('id');
            $operationIds = StockOperation::whereIn('id', $originalIds)->orWhereIn('reversal_of_id', $originalIds)->pluck('id');
            $inventoryIds = Inventory::where('batch_id', $batch->id)->pluck('id');
            if (InventoryTransaction::whereIn('inventory_id', $inventoryIds)->whereNotIn('stock_operation_id', $operationIds)->exists()
                || InventoryTransaction::whereIn('stock_operation_id', $operationIds)->whereNotIn('inventory_id', $inventoryIds)->exists()) {
                throw new RuntimeException('Non-fixture reference found; cleanup stopped.');
            }
            $reversalMoveIds = InventoryTransaction::whereIn('stock_operation_id', $operationIds)->whereNotNull('reversal_of_transaction_id')->pluck('id');
            DB::table('inventory_transactions')->whereIn('id', $reversalMoveIds)->delete();
            DB::table('inventory_transactions')->whereIn('stock_operation_id', $operationIds)->delete();
            DB::table('stock_operations')->whereIn('id', $operationIds)->whereNotNull('reversal_of_id')->delete();
            DB::table('stock_operations')->whereIn('id', $operationIds)->delete();
            DB::table('inventory')->whereIn('id', $inventoryIds)->delete();
            DB::table('product_batches')->where('id', $batch->id)->where('batch_number', $tag)->delete();
            DB::table('products')->where('id', $product->id)->where('name', $tag)->delete();
            DB::table('categories')->where('id', $category->id)->where('name', $tag)->delete();
        });
        echo "TAGGED_VERIFICATION_FIXTURES_CLEANED_USER_DATA_PRESERVED\n";
    }
}
