<?php

// 1. Resolve project root by walking up to find artisan
$projectRoot = __DIR__;
while (! file_exists($projectRoot.'/artisan') && dirname($projectRoot) !== $projectRoot) {
    $projectRoot = dirname($projectRoot);
}

if (! file_exists($projectRoot.'/artisan')) {
    fwrite(STDERR, 'FATAL: Could not locate project root containing artisan from '.__DIR__."\n");
    exit(1);
}

require $projectRoot.'/vendor/autoload.php';
$app = require $projectRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// 2. Verify connection to the intended test database on port 3307
$expectedDb = 'smart_mini_erp_perf';
$expectedPort = 3307;

$actualDb = DB::connection()->getDatabaseName();
$actualPort = (int) DB::connection()->getConfig('port');

if ($actualDb !== $expectedDb || $actualPort !== $expectedPort) {
    fwrite(STDERR, sprintf(
        "FATAL: Database connection mismatch! Expected '%s' on port %d, but connected to '%s' on port %d.\n".
        "Please switch .env to perf mode before running this audit script.\n",
        $expectedDb, $expectedPort, $actualDb, $actualPort
    ));
    exit(1);
}

echo "Database verification: Connected to '{$actualDb}' on port {$actualPort} (verified test environment).\n";

// 3. Load documented execution window and row counts expectations
$evidenceDir = __DIR__;
$statusPreFile = $evidenceDir.'/mysql_status_pre.json';
$statusPostFile = $evidenceDir.'/mysql_status_post.json';
$rowPreFile = $evidenceDir.'/row_counts_round2_pre.json';
$rowPostFile = $evidenceDir.'/row_counts_round2_post.json';

// Fallback search in .cache/bench if needed
if (! file_exists($statusPreFile)) {
    $statusPreFile = $projectRoot.'/.cache/bench/mysql_status_pre.json';
}
if (! file_exists($statusPostFile)) {
    $statusPostFile = $projectRoot.'/.cache/bench/mysql_status_post.json';
}
if (! file_exists($rowPreFile)) {
    $rowPreFile = $projectRoot.'/.cache/bench/row_counts_round2_pre.json';
}
if (! file_exists($rowPostFile)) {
    $rowPostFile = $projectRoot.'/.cache/bench/row_counts_round2_post.json';
}

if (! file_exists($statusPreFile) || ! file_exists($statusPostFile)) {
    fwrite(STDERR, "FATAL: Execution window evidence files (mysql_status_pre/post.json) not found!\n");
    exit(1);
}
if (! file_exists($rowPreFile) || ! file_exists($rowPostFile)) {
    fwrite(STDERR, "FATAL: Row counts expectation files (row_counts_round2_pre/post.json) not found!\n");
    exit(1);
}

$statusPre = json_decode(file_get_contents($statusPreFile), true);
$statusPost = json_decode(file_get_contents($statusPostFile), true);
$rowPre = json_decode(file_get_contents($rowPreFile), true);
$rowPost = json_decode(file_get_contents($rowPostFile), true);

$windowStart = $statusPre['timestamp'];
$windowEnd = $statusPost['timestamp'];

// Numerical expectations derived from pre/post row count snapshots ONLY
$expectedPos = $rowPost['purchase_orders'] - $rowPre['purchase_orders']; // 339
$expectedSos = $rowPost['sales_orders'] - $rowPre['sales_orders'];       // 383
$expectedOps = $rowPost['stock_operations'] - $rowPre['stock_operations']; // 722
$expectedTxs = $rowPost['inventory_transactions'] - $rowPre['inventory_transactions']; // 730

// Support CLI overrides for proving failure detection
$targetPos = $expectedPos;
$targetSos = $expectedSos;
$targetOps = $expectedOps;
$targetTxs = $expectedTxs;
foreach ($argv as $arg) {
    if (preg_match('/^--expect-pos=(\d+)$/', $arg, $m)) {
        $targetPos = (int) $m[1];
    }
    if (preg_match('/^--expect-sos=(\d+)$/', $arg, $m)) {
        $targetSos = (int) $m[1];
    }
    if (preg_match('/^--expect-ops=(\d+)$/', $arg, $m)) {
        $targetOps = (int) $m[1];
    }
    if (preg_match('/^--expect-txs=(\d+)$/', $arg, $m)) {
        $targetTxs = (int) $m[1];
    }
}

// Verify timezone consistency
$appTz = date_default_timezone_get();
echo sprintf(
    "Documented Execution Window: [%s to %s] (Timezone: %s)\n",
    $windowStart, $windowEnd, $appTz
);

// 4. Select benchmark documents based on documented notes AND execution window
// Purchase Orders
$poQuery = DB::table('purchase_orders')
    ->where('notes', 'like', 'Load test PO from VU %')
    ->whereBetween('created_at', [$windowStart, $windowEnd])
    ->get();

$actualPosCount = $poQuery->count();
$poInvalidStatus = $poQuery->where('status', '!==', 'received')->count();
$poNullStockOps = $poQuery->whereNull('stock_operation_id')->count();
$poOpIds = $poQuery->pluck('stock_operation_id')->all();
$poDuplicateOps = count($poOpIds) - count(array_unique($poOpIds));

if ($poInvalidStatus > 0 || $poNullStockOps > 0 || $poDuplicateOps > 0) {
    fwrite(STDERR, sprintf(
        "FATAL: PO data integrity violation! Unreceived: %d, Null stock_ops: %d, Duplicates: %d\n",
        $poInvalidStatus, $poNullStockOps, $poDuplicateOps
    ));
    exit(1);
}

// Sales Orders
$soQuery = DB::table('sales_orders')
    ->where('notes', 'like', 'Load test SO from VU %')
    ->whereBetween('created_at', [$windowStart, $windowEnd])
    ->get();

$actualSosCount = $soQuery->count();
$soInvalidStatus = $soQuery->where('status', '!==', 'confirmed')->count();
$soNullStockOps = $soQuery->whereNull('stock_operation_id')->count();
$soOpIds = $soQuery->pluck('stock_operation_id')->all();
$soDuplicateOps = count($soOpIds) - count(array_unique($soOpIds));

if ($soInvalidStatus > 0 || $soNullStockOps > 0 || $soDuplicateOps > 0) {
    fwrite(STDERR, sprintf(
        "FATAL: SO data integrity violation! Unconfirmed: %d, Null stock_ops: %d, Duplicates: %d\n",
        $soInvalidStatus, $soNullStockOps, $soDuplicateOps
    ));
    exit(1);
}

// Combined verified stock operations list
$selectedOpIds = array_values(array_unique(array_merge($poOpIds, $soOpIds)));
$actualOpsCount = count($selectedOpIds);

// Verify operations exist and are linked to these documents
$verifiedOps = DB::table('stock_operations')
    ->whereIn('id', $selectedOpIds)
    ->get();

if ($verifiedOps->count() !== $actualOpsCount) {
    fwrite(STDERR, "FATAL: Stock operations mismatch between document references and stock_operations table!\n");
    exit(1);
}

// 5. Save sample manifest as an independent evidence artifact (sample_manifest.json)
$manifestPath = $evidenceDir.'/sample_manifest.json';
$manifestData = [
    'manifest_metadata' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'benchmark_round' => 'Round 2 Steady-State Benchmark',
        'timezone' => $appTz,
    ],
    'selection_criteria' => [
        'window_start' => $windowStart,
        'window_start_source' => 'mysql_status_pre.json',
        'window_end' => $windowEnd,
        'window_end_source' => 'mysql_status_post.json',
        'po_notes_pattern' => 'Load test PO from VU %',
        'po_required_status' => 'received',
        'so_notes_pattern' => 'Load test SO from VU %',
        'so_required_status' => 'confirmed',
    ],
    'summary_counts' => [
        'purchase_orders_count' => $actualPosCount,
        'sales_orders_count' => $actualSosCount,
        'stock_operations_count' => $actualOpsCount,
    ],
    'purchase_order_ids' => $poQuery->pluck('id')->values()->all(),
    'sales_order_ids' => $soQuery->pluck('id')->values()->all(),
    'stock_operation_ids' => $selectedOpIds,
];
file_put_contents($manifestPath, json_encode($manifestData, JSON_PRETTY_PRINT));
echo 'Saved sample manifest: '.basename($manifestPath)."\n";

// 6. Query inventory transactions strictly using the extracted manifest stock_operation_ids
$benchTxs = DB::table('inventory_transactions')
    ->whereIn('stock_operation_id', $selectedOpIds)
    ->get();

$actualTxsCount = $benchTxs->count();

// Derive transaction distribution dynamically from database query
$distributionRows = DB::table('inventory_transactions')
    ->whereIn('stock_operation_id', $selectedOpIds)
    ->select('stock_operation_id', DB::raw('count(*) as tx_count'))
    ->groupBy('stock_operation_id')
    ->get();

$txDistribution = [];
$multiTxOpDetails = [];
foreach ($distributionRows as $row) {
    $c = (int) $row->tx_count;
    $txDistribution[$c] = ($txDistribution[$c] ?? 0) + 1;
    if ($c > 1) {
        $multiTxOpDetails[] = (int) $row->stock_operation_id;
    }
}
ksort($txDistribution);

echo "\n=== Benchmark Sample Results (Identified by Notes + Time Window) ===\n";
echo "Purchase Orders selected: {$actualPosCount} (all 'received', zero null stock_ops)\n";
echo "Sales Orders selected: {$actualSosCount} (all 'confirmed', zero null stock_ops)\n";
echo "Stock operations linked: {$actualOpsCount} (all verified in stock_operations)\n";
echo "Inventory transactions linked: {$actualTxsCount}\n";

echo "Transaction count distribution per operation (derived dynamically):\n";
$derivedTotalTxs = 0;
foreach ($txDistribution as $txCount => $opCount) {
    $subTotal = $txCount * $opCount;
    $derivedTotalTxs += $subTotal;
    echo sprintf("  - %d operation(s) have %d transaction(s) (= %d transactions)\n", $opCount, $txCount, $subTotal);
}

echo 'Operations with multiple transactions (count: '.count($multiTxOpDetails)."):\n";
foreach ($multiTxOpDetails as $opId) {
    $op = DB::table('stock_operations')->where('id', $opId)->first();
    $txs = DB::table('inventory_transactions')->where('stock_operation_id', $opId)->get();
    echo "  Op ID: {$op->id}, Type: {$op->type}, Reference: {$op->reference}\n";
    foreach ($txs as $t) {
        echo "    Tx ID: {$t->id}, InvID: {$t->inventory_id}, Allocation#: {$t->allocation_number}, Qty: {$t->quantity}\n";
    }
}

// 7. Subsequent documents & operations outside the benchmark sample (independent section)
echo "\n=== Subsequent Operations (Outside Benchmark Sample Window) ===\n";
$subsequentSos = DB::table('sales_orders')
    ->where('created_at', '>', $windowEnd)
    ->get();
$subsequentPos = DB::table('purchase_orders')
    ->where('created_at', '>', $windowEnd)
    ->get();

$subsequentOpIds = array_filter(array_merge(
    $subsequentPos->pluck('stock_operation_id')->all(),
    $subsequentSos->pluck('stock_operation_id')->all()
));

echo 'Subsequent POs outside window: '.$subsequentPos->count()."\n";
echo 'Subsequent SOs outside window: '.$subsequentSos->count()."\n";
foreach ($subsequentSos as $s) {
    echo "  Subsequent SO ID: {$s->id}, Notes: '{$s->notes}', Status: {$s->status}, Created: {$s->created_at}, StockOp: {$s->stock_operation_id}\n";
}

$subsequentOps = DB::table('stock_operations')
    ->whereIn('id', $subsequentOpIds)
    ->get();
echo 'Subsequent operations count: '.$subsequentOps->count()."\n";

$subsequentTxs = DB::table('inventory_transactions')
    ->whereIn('stock_operation_id', $subsequentOpIds)
    ->get();
echo 'Subsequent transactions count: '.$subsequentTxs->count()."\n";
foreach ($subsequentTxs as $stx) {
    echo "  Tx ID: {$stx->id}, Linked Op ID: {$stx->stock_operation_id}, Qty: {$stx->quantity}\n";
}

// 8. Validation and Exit Code
echo "\n=== Comparison Against Documented Expectations ===\n";
echo sprintf("Target: POs = %d, SOs = %d, Operations = %d, Transactions = %d\n", $targetPos, $targetSos, $targetOps, $targetTxs);
echo sprintf(
    "Actual: POs = %d, SOs = %d, Operations = %d, Transactions = %d (Derived Sum = %d)\n",
    $actualPosCount, $actualSosCount, $actualOpsCount, $actualTxsCount, $derivedTotalTxs
);

$posMatch = ($actualPosCount === $targetPos);
$sosMatch = ($actualSosCount === $targetSos);
$opsMatch = ($actualOpsCount === $targetOps);
$txsMatch = ($actualTxsCount === $targetTxs) && ($derivedTotalTxs === $targetTxs);

if ($posMatch && $sosMatch && $opsMatch && $txsMatch) {
    echo "MATCH: Verified benchmark sample against documented expectations.\n";
    exit(0);
} else {
    fwrite(STDERR, sprintf(
        "MISMATCH: POs: %s, SOs: %s, Operations: %s, Transactions: %s\n",
        $posMatch ? 'YES' : 'NO',
        $sosMatch ? 'YES' : 'NO',
        $opsMatch ? 'YES' : 'NO',
        $txsMatch ? 'YES' : 'NO'
    ));
    exit(1);
}
