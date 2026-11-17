<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

echo "Starting 10,000 documents data generation...\n";
$startTotal = microtime(true);

mt_srand(20261116);

// Disable foreign key checks for clean wipe
DB::statement('SET FOREIGN_KEY_CHECKS=0');
$tablesToTruncate = [
    'inventory_transactions',
    'stock_operations',
    'inventory',
    'sales_order_items',
    'sales_orders',
    'purchase_order_items',
    'purchase_orders',
    'product_batches',
    'customer_product_prices',
    'products',
    'categories',
    'suppliers',
    'customers',
    'users',
    'role_permissions',
    'roles',
    'permissions',
    'warehouses',
];

foreach ($tablesToTruncate as $tbl) {
    DB::table($tbl)->truncate();
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');

echo "Tables truncated.\n";

$now = CarbonImmutable::now('UTC')->toDateTimeString();

// 1. Warehouse
DB::table('warehouses')->insert([
    'id' => 1,
    'code' => 'main',
    'name' => 'Main Warehouse',
    'location' => 'Central Hub',
    'description' => 'Primary test facility',
    'created_at' => $now,
    'updated_at' => $now,
]);

// 2. Permissions
$permCodes = Permission::CODES;
$permRows = array_map(fn ($c) => ['code' => $c], $permCodes);
DB::table('permissions')->insert($permRows);
$allPerms = DB::table('permissions')->get()->keyBy('code');

// 3. Roles
$rolesData = [
    ['id' => 1, 'code' => 'admin', 'name' => 'Administrator', 'is_system' => 1],
    ['id' => 2, 'code' => 'purchasing', 'name' => 'Purchasing Officer', 'is_system' => 0],
    ['id' => 3, 'code' => 'sales', 'name' => 'Sales Officer', 'is_system' => 0],
    ['id' => 4, 'code' => 'inventory', 'name' => 'Inventory Controller', 'is_system' => 0],
];
foreach ($rolesData as $r) {
    DB::table('roles')->insert([...$r, 'created_at' => $now, 'updated_at' => $now]);
}

// Role permissions
$rolePermMap = [
    'admin' => $permCodes,
    'purchasing' => ['dashboard.view', 'purchases.view', 'purchases.manage', 'purchases.receive', 'inventory.post', 'suppliers.view', 'products.view', 'reports.purchases'],
    'sales' => ['dashboard.view', 'sales.view', 'sales.manage', 'sales.post', 'inventory.post', 'customers.view', 'prices.view', 'products.view', 'reports.sales'],
    'inventory' => ['dashboard.view', 'inventory.view', 'inventory.post', 'batches.view', 'batches.manage', 'warehouses.view', 'reports.inventory', 'purchases.view', 'sales.view'],
];

$rolePermInserts = [];
foreach ($rolePermMap as $rCode => $codes) {
    $roleId = DB::table('roles')->where('code', $rCode)->value('id');
    foreach ($codes as $c) {
        if (isset($allPerms[$c])) {
            $rolePermInserts[] = [
                'role_id' => $roleId,
                'permission_id' => $allPerms[$c]->id,
            ];
        }
    }
}
DB::table('role_permissions')->insert($rolePermInserts);

// 4. 20 Users (5 per role)
$userPasswordHash = Hash::make(env('BENCH_USER_PASSWORD', 'REDACTED_PASSWORD'));
$usersToInsert = [];
$userIdCounter = 1;
$userIdsByRole = [
    'admin' => [],
    'purchasing' => [],
    'sales' => [],
    'inventory' => [],
];

foreach (['admin', 'purchasing', 'sales', 'inventory'] as $roleCode) {
    $rId = DB::table('roles')->where('code', $roleCode)->value('id');
    for ($i = 1; $i <= 5; $i++) {
        $uId = $userIdCounter++;
        $userIdsByRole[$roleCode][] = $uId;
        $usersToInsert[] = [
            'id' => $uId,
            'name' => ucfirst($roleCode)." User $i",
            'email' => "{$roleCode}{$i}@bench.local",
            'password' => $userPasswordHash,
            'role_id' => $rId,
            'is_active' => 1,
            'auth_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
DB::table('users')->insert($usersToInsert);
echo "Seeded 4 roles, 20 users.\n";

// 5. Master Data: 20 Categories, 50 Suppliers, 100 Customers, 200 Products
$categories = [];
for ($i = 1; $i <= 20; $i++) {
    $categories[] = [
        'id' => $i,
        'name' => "Category $i",
        'description' => "Description for Category $i",
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}
DB::table('categories')->insert($categories);

$suppliers = [];
for ($i = 1; $i <= 50; $i++) {
    $suppliers[] = [
        'id' => $i,
        'name' => 'Supplier '.sprintf('%03d', $i).' Trading Co',
        'phone' => '+96650'.sprintf('%07d', $i),
        'email' => "supplier{$i}@benchsupplier.local",
        'address' => "Industrial Zone, Street {$i}, Riyadh",
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}
DB::table('suppliers')->insert($suppliers);

$customers = [];
for ($i = 1; $i <= 100; $i++) {
    $customers[] = [
        'id' => $i,
        'name' => 'Customer '.sprintf('%03d', $i).' Market',
        'phone' => '+96655'.sprintf('%07d', $i),
        'email' => "customer{$i}@benchclient.local",
        'address' => "Commercial District, Unit {$i}, Jeddah",
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}
DB::table('customers')->insert($customers);

$products = [];
for ($i = 1; $i <= 200; $i++) {
    $catId = (($i - 1) % 20) + 1;
    $products[] = [
        'id' => $i,
        'category_id' => $catId,
        'name' => 'Product SKU-'.sprintf('%04d', $i),
        'description' => "Standard inventory item $i",
        'base_unit' => 'piece',
        'pieces_per_carton' => 12,
        'purchase_unit' => 'carton',
        'selling_unit' => 'piece',
        'purchase_price' => '10.000000',
        'selling_price' => '1.500000',
        'reorder_level' => 10,
        'is_active' => 1,
        'stock_started_at' => '2026-01-01 00:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ];
}
DB::table('products')->insert($products);
echo "Seeded master data (20 cats, 50 suppliers, 100 customers, 200 products).\n";

// Helper for date generation
function randomDate(string $startDate, string $endDate): string
{
    $min = strtotime($startDate);
    $max = strtotime($endDate);
    $val = mt_rand($min, $max);

    return date('Y-m-d', $val);
}

// 6. Generate 5,000 Purchase Orders
// - 3,000 received
// - 1,500 draft
// - 500 cancelled
echo "Generating 5,000 Purchase Orders...\n";
$poStart = microtime(true);

$poCount = 5000;
$poReceivedCount = 3000;
$poDraftCount = 1500;
$poCancelledCount = 500;

$poOrders = [];
$poItems = [];
$productBatches = [];
$stockOps = [];
$invTxns = [];
$inventoryMap = []; // batch_id => ['id' => x, 'warehouse_id' => 1, 'batch_id' => y, 'quantity' => q]
$batchIdCounter = 1;
$stockOpIdCounter = 1;
$invTxnIdCounter = 1;
$invIdCounter = 1;

$purchasingUserIds = $userIdsByRole['purchasing'];

for ($poId = 1; $poId <= $poCount; $poId++) {
    $supplierId = (($poId - 1) % 50) + 1;
    $supplierName = 'Supplier '.sprintf('%03d', $supplierId).' Trading Co';
    $userId = $purchasingUserIds[($poId - 1) % count($purchasingUserIds)];
    $orderDate = randomDate('2026-01-01', '2026-10-31');

    if ($poId <= $poReceivedCount) {
        $status = 'received';
    } elseif ($poId <= $poReceivedCount + $poDraftCount) {
        $status = 'draft';
    } else {
        $status = 'cancelled';
    }

    $cKey = Str::uuid()->toString();
    $cHash = hash('sha256', "PO_CREATE_{$poId}_{$cKey}");

    // 2 items per PO
    // pick 2 distinct products
    $prod1 = (($poId * 2 - 1) % 200) + 1;
    $prod2 = (($poId * 2) % 200) + 1;
    if ($prod1 === $prod2) {
        $prod2 = ($prod1 % 200) + 1;
    }

    $itemDefs = [
        ['product_id' => $prod1, 'qty' => mt_rand(10, 40)], // cartons
        ['product_id' => $prod2, 'qty' => mt_rand(10, 40)], // cartons
    ];

    $totalAmount = 0.0;
    foreach ($itemDefs as $it) {
        $totalAmount += $it['qty'] * 10.0;
    }

    $poRecord = [
        'id' => $poId,
        'supplier_id' => $supplierId,
        'supplier_name' => $supplierName,
        'user_id' => $userId,
        'order_date' => $orderDate,
        'status' => $status,
        'version' => ($status === 'draft' ? 1 : 2),
        'notes' => "Benchmark PO #{$poId}",
        'total_amount' => number_format($totalAmount, 6, '.', ''),
        'creation_key' => $cKey,
        'creation_hash' => $cHash,
        'receipt_key' => null,
        'receipt_hash' => null,
        'stock_operation_id' => null,
        'received_by' => null,
        'received_at' => null,
        'cancelled_by' => null,
        'cancelled_at' => null,
        'created_at' => $orderDate.' 08:00:00',
        'updated_at' => $orderDate.' 08:00:00',
    ];

    if ($status === 'received') {
        $rKey = Str::uuid()->toString();
        $rHash = hash('sha256', "PO_RECEIVE_{$poId}_{$rKey}");
        $rDate = date('Y-m-d H:i:s', strtotime($orderDate.' +1 day 10:00:00'));

        $opId = $stockOpIdCounter++;
        $stockOps[] = [
            'id' => $opId,
            'warehouse_id' => 1,
            'user_id' => $userId,
            'type' => 'receipt',
            'idempotency_key' => $rKey,
            'payload_hash' => $rHash,
            'reference' => "PO:{$poId}",
            'reason' => null,
            'reversal_of_id' => null,
            'posted_at' => $rDate,
        ];

        $poRecord['receipt_key'] = $rKey;
        $poRecord['receipt_hash'] = $rHash;
        $poRecord['stock_operation_id'] = $opId;
        $poRecord['received_by'] = $userId;
        $poRecord['received_at'] = $rDate;
        $poRecord['updated_at'] = $rDate;

        foreach ($itemDefs as $idx => $it) {
            $lineNo = $idx + 1;
            $batchId = $batchIdCounter++;
            $batchNum = "BATCH-PO-{$poId}-{$lineNo}";
            $prodDate = $orderDate;
            $expDate = date('Y-m-d', strtotime($orderDate.' +'.mt_rand(180, 540).' days'));

            $productBatches[] = [
                'id' => $batchId,
                'product_id' => $it['product_id'],
                'batch_number' => $batchNum,
                'production_date' => $prodDate,
                'expiration_date' => $expDate,
                'stock_started_at' => $rDate,
                'created_at' => $rDate,
                'updated_at' => $rDate,
            ];

            $invId = $invIdCounter++;
            $pieces = $it['qty'] * 12;

            $inventoryMap[$batchId] = [
                'id' => $invId,
                'warehouse_id' => 1,
                'batch_id' => $batchId,
                'product_id' => $it['product_id'],
                'expiration_date' => $expDate,
                'quantity' => $pieces, // will be updated when SO confirms
                'created_at' => $rDate,
                'updated_at' => $rDate,
            ];

            $invTxns[] = [
                'id' => $invTxnIdCounter++,
                'stock_operation_id' => $opId,
                'inventory_id' => $invId,
                'line_number' => $lineNo,
                'allocation_number' => 1,
                'quantity' => $pieces,
                'input_quantity' => $it['qty'],
                'unit' => 'carton',
                'conversion_factor' => 12,
                'reversal_of_transaction_id' => null,
            ];

            $poItems[] = [
                'purchase_order_id' => $poId,
                'line_number' => $lineNo,
                'product_id' => $it['product_id'],
                'product_name' => 'Product SKU-'.sprintf('%04d', $it['product_id']),
                'batch_id' => $batchId,
                'quantity' => $it['qty'],
                'unit' => 'carton',
                'conversion_factor' => 12,
                'unit_price' => '10.000000',
                'subtotal' => number_format($it['qty'] * 10.0, 6, '.', ''),
            ];
        }
    } else {
        if ($status === 'cancelled') {
            $poRecord['cancelled_by'] = $userId;
            $poRecord['cancelled_at'] = $orderDate.' 12:00:00';
            $poRecord['updated_at'] = $orderDate.' 12:00:00';
        }

        foreach ($itemDefs as $idx => $it) {
            $lineNo = $idx + 1;
            $poItems[] = [
                'purchase_order_id' => $poId,
                'line_number' => $lineNo,
                'product_id' => $it['product_id'],
                'product_name' => 'Product SKU-'.sprintf('%04d', $it['product_id']),
                'batch_id' => null,
                'quantity' => $it['qty'],
                'unit' => 'carton',
                'conversion_factor' => 12,
                'unit_price' => '10.000000',
                'subtotal' => number_format($it['qty'] * 10.0, 6, '.', ''),
            ];
        }
    }

    $poOrders[] = $poRecord;
}

echo 'PO objects generated in memory ('.count($poOrders).' POs, '.count($poItems)." items).\n";

// Group inventory by product and sort by FEFO (expiration_date asc, batch_id asc) for SO allocation
$fefoByProduct = [];
foreach ($inventoryMap as $batchId => $inv) {
    $fefoByProduct[$inv['product_id']][] = $batchId;
}
foreach ($fefoByProduct as $prodId => &$bIds) {
    usort($bIds, function ($a, $b) use ($inventoryMap) {
        $expA = $inventoryMap[$a]['expiration_date'];
        $expB = $inventoryMap[$b]['expiration_date'];
        if ($expA === $expB) {
            return $a <=> $b;
        }

        return $expA <=> $expB;
    });
}
unset($bIds);

// 7. Generate 5,000 Sales Orders
// - 3,000 confirmed
// - 1,500 draft
// - 500 cancelled
echo "Generating 5,000 Sales Orders...\n";
$soCount = 5000;
$soConfirmedCount = 3000;
$soDraftCount = 1500;
$soCancelledCount = 500;

$soOrders = [];
$soItems = [];
$salesUserIds = $userIdsByRole['sales'];

for ($soId = 1; $soId <= $soCount; $soId++) {
    $customerId = (($soId - 1) % 100) + 1;
    $customerName = 'Customer '.sprintf('%03d', $customerId).' Market';
    $userId = $salesUserIds[($soId - 1) % count($salesUserIds)];
    $orderDate = randomDate('2026-06-01', '2026-11-15');

    if ($soId <= $soConfirmedCount) {
        $status = 'confirmed';
    } elseif ($soId <= $soConfirmedCount + $soDraftCount) {
        $status = 'draft';
    } else {
        $status = 'cancelled';
    }

    $cKey = Str::uuid()->toString();
    $cHash = hash('sha256', "SO_CREATE_{$soId}_{$cKey}");

    // 2 items per SO
    $prod1 = (($soId * 2 - 1) % 200) + 1;
    $prod2 = (($soId * 2) % 200) + 1;
    if ($prod1 === $prod2) {
        $prod2 = ($prod1 % 200) + 1;
    }

    // Small quantities so inventory is never depleted: 2 to 5 cartons (24 to 60 pieces)
    $itemDefs = [
        ['product_id' => $prod1, 'qty' => mt_rand(2, 5), 'unit' => 'carton'],
        ['product_id' => $prod2, 'qty' => mt_rand(5, 20), 'unit' => 'piece'],
    ];

    $totalAmount = 0.0;
    foreach ($itemDefs as &$it) {
        if ($it['unit'] === 'carton') {
            $unitPrice = 18.00; // 12 pieces * 1.50
            $it['conv'] = 12;
            $it['pieces'] = $it['qty'] * 12;
        } else {
            $unitPrice = 1.50;
            $it['conv'] = 1;
            $it['pieces'] = $it['qty'];
        }
        $it['unit_price'] = $unitPrice;
        $it['subtotal'] = $it['qty'] * $unitPrice;
        $totalAmount += $it['subtotal'];
    }
    unset($it);

    $soRecord = [
        'id' => $soId,
        'customer_id' => $customerId,
        'customer_name' => $customerName,
        'user_id' => $userId,
        'order_date' => $orderDate,
        'status' => $status,
        'version' => ($status === 'draft' ? 1 : 2),
        'notes' => "Benchmark SO #{$soId}",
        'total_amount' => number_format($totalAmount, 6, '.', ''),
        'creation_key' => $cKey,
        'creation_hash' => $cHash,
        'confirmation_key' => null,
        'confirmation_hash' => null,
        'stock_operation_id' => null,
        'confirmed_by' => null,
        'confirmed_at' => null,
        'cancelled_by' => null,
        'cancelled_at' => null,
        'created_at' => $orderDate.' 09:00:00',
        'updated_at' => $orderDate.' 09:00:00',
    ];

    if ($status === 'confirmed') {
        $confKey = Str::uuid()->toString();
        $confHash = hash('sha256', "SO_CONFIRM_{$soId}_{$confKey}");
        $confDate = date('Y-m-d H:i:s', strtotime($orderDate.' +1 day 14:00:00'));

        $opId = $stockOpIdCounter++;
        $stockOps[] = [
            'id' => $opId,
            'warehouse_id' => 1,
            'user_id' => $userId,
            'type' => 'issue',
            'idempotency_key' => $confKey,
            'payload_hash' => $confHash,
            'reference' => "SO:{$soId}",
            'reason' => null,
            'reversal_of_id' => null,
            'posted_at' => $confDate,
        ];

        $soRecord['confirmation_key'] = $confKey;
        $soRecord['confirmation_hash'] = $confHash;
        $soRecord['stock_operation_id'] = $opId;
        $soRecord['confirmed_by'] = $userId;
        $soRecord['confirmed_at'] = $confDate;
        $soRecord['updated_at'] = $confDate;

        foreach ($itemDefs as $idx => $it) {
            $lineNo = $idx + 1;
            $soItems[] = [
                'sales_order_id' => $soId,
                'line_number' => $lineNo,
                'product_id' => $it['product_id'],
                'product_name' => 'Product SKU-'.sprintf('%04d', $it['product_id']),
                'quantity' => $it['qty'],
                'unit' => $it['unit'],
                'conversion_factor' => $it['conv'],
                'unit_price' => number_format($it['unit_price'], 6, '.', ''),
                'subtotal' => number_format($it['subtotal'], 6, '.', ''),
                'price_source' => 'catalog',
                'customer_price_id' => null,
                'price_rule_version' => null,
            ];

            // Allocate from inventory according to FEFO
            $remaining = $it['pieces'];
            $allocation = 0;
            $batchesForProd = &$fefoByProduct[$it['product_id']];

            foreach ($batchesForProd as $bId) {
                if ($remaining <= 0) {
                    break;
                }
                $invRec = &$inventoryMap[$bId];
                if ($invRec['quantity'] <= 0) {
                    continue;
                }
                $take = min($remaining, $invRec['quantity']);
                $allocation++;

                $invTxns[] = [
                    'id' => $invTxnIdCounter++,
                    'stock_operation_id' => $opId,
                    'inventory_id' => $invRec['id'],
                    'line_number' => $lineNo,
                    'allocation_number' => $allocation,
                    'quantity' => -$take,
                    'input_quantity' => $it['qty'],
                    'unit' => $it['unit'],
                    'conversion_factor' => $it['conv'],
                    'reversal_of_transaction_id' => null,
                ];

                $invRec['quantity'] -= $take;
                $remaining -= $take;
            }
            unset($invRec);
            unset($batchesForProd);

            if ($remaining > 0) {
                throw new RuntimeException("Insufficient stock for product {$it['product_id']} on SO {$soId}!");
            }
        }
    } else {
        if ($status === 'cancelled') {
            $soRecord['cancelled_by'] = $userId;
            $soRecord['cancelled_at'] = $orderDate.' 13:00:00';
            $soRecord['updated_at'] = $orderDate.' 13:00:00';
        }

        foreach ($itemDefs as $idx => $it) {
            $lineNo = $idx + 1;
            $soItems[] = [
                'sales_order_id' => $soId,
                'line_number' => $lineNo,
                'product_id' => $it['product_id'],
                'product_name' => 'Product SKU-'.sprintf('%04d', $it['product_id']),
                'quantity' => $it['qty'],
                'unit' => $it['unit'],
                'conversion_factor' => $it['conv'],
                'unit_price' => number_format($it['unit_price'], 6, '.', ''),
                'subtotal' => number_format($it['subtotal'], 6, '.', ''),
                'price_source' => 'catalog',
                'customer_price_id' => null,
                'price_rule_version' => null,
            ];
        }
    }

    $soOrders[] = $soRecord;
}

echo 'SO objects generated in memory ('.count($soOrders).' SOs, '.count($soItems)." items).\n";

// 8. Bulk insert into Database in chunks
function insertInChunks(string $table, array $rows, int $chunkSize = 1000): void
{
    $total = count($rows);
    $chunks = array_chunk($rows, $chunkSize);
    echo "Inserting $total rows into $table in ".count($chunks)." chunks...\n";
    foreach ($chunks as $idx => $chunk) {
        DB::table($table)->insert($chunk);
    }
}

// Order of insertions to respect foreign keys:
// 1. product_batches
insertInChunks('product_batches', $productBatches);

// 2. stock_operations
insertInChunks('stock_operations', $stockOps);

// 3. inventory
$inventoryRowsToInsert = [];
foreach ($inventoryMap as $inv) {
    $inventoryRowsToInsert[] = [
        'id' => $inv['id'],
        'warehouse_id' => $inv['warehouse_id'],
        'batch_id' => $inv['batch_id'],
        'quantity' => $inv['quantity'],
        'created_at' => $inv['created_at'],
        'updated_at' => $inv['updated_at'],
    ];
}
insertInChunks('inventory', $inventoryRowsToInsert);

// 4. inventory_transactions
insertInChunks('inventory_transactions', $invTxns);

// 5. purchase_orders
insertInChunks('purchase_orders', $poOrders);

// 6. purchase_order_items
insertInChunks('purchase_order_items', $poItems);

// 7. sales_orders
insertInChunks('sales_orders', $soOrders);

// 8. sales_order_items
insertInChunks('sales_order_items', $soItems);

$elapsedTotal = microtime(true) - $startTotal;
echo 'Completed all insertions in '.round($elapsedTotal, 2)."s!\n";
