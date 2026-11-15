<?php

/**
 * Setup script for Audit Test Environment.
 * Seeds or updates the test users in the isolated test database.
 * Generates and stores credentials in untracked .cache/audit_env.json.
 */

require __DIR__.'/../../../../vendor/autoload.php';
$app = require_once __DIR__.'/../../../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Admin Role
$adminRole = Role::where('code', 'admin')->first();
if (! $adminRole) {
    $adminRole = new Role;
    $adminRole->forceFill(['name' => 'مدير النظام', 'code' => 'admin', 'is_system' => true])->save();
}
$adminRole->permissions()->sync(Permission::all());

// Purchasing Role
$purchaseRole = Role::firstOrCreate(['code' => 'purchasing_officer'], ['name' => 'مسؤول مشتريات', 'is_system' => false]);
$purchasePermCodes = [
    'purchases.view', 'purchases.manage', 'purchases.receive', 'purchases.cancel',
    'inventory.view', 'inventory.post', 'contacts.view', 'contacts.manage', 'master.view', 'master.manage',
    'reports.purchases',
];
$purchasePerms = Permission::whereIn('code', $purchasePermCodes)->pluck('id');
$purchaseRole->permissions()->sync($purchasePerms);

// Sales Role
$salesRole = Role::firstOrCreate(['code' => 'sales_officer'], ['name' => 'مسؤول مبيعات', 'is_system' => false]);
$salesPermCodes = [
    'sales.view', 'sales.manage', 'sales.post', 'sales.confirm', 'sales.cancel',
    'pricing.view', 'pricing.manage',
    'inventory.view', 'inventory.post', 'contacts.view', 'master.view',
    'reports.sales', 'reports.inventory',
];
$salesPerms = Permission::whereIn('code', $salesPermCodes)->pluck('id');
$salesRole->permissions()->sync($salesPerms);

// Read passwords from ENV or generate secure session passwords
$adminPass = getenv('AUDIT_ADMIN_PASSWORD') ?: ('Adm_'.Str::random(16).'!Aa1');
$purchasePass = getenv('AUDIT_PURCHASING_PASSWORD') ?: ('Pur_'.Str::random(16).'!Aa1');
$salesPass = getenv('AUDIT_SALES_PASSWORD') ?: ('Sal_'.Str::random(16).'!Aa1');

// 1. Admin user
$adminUser = User::where('email', 'admin@audit.local')->first() ?? new User;
$adminUser->forceFill([
    'name' => 'مدير التدقيق',
    'email' => 'admin@audit.local',
    'password' => Hash::make($adminPass),
    'role_id' => $adminRole->id,
    'is_active' => true,
    'auth_version' => 1,
])->save();

// 2. Purchasing user
$purchasingUser = User::where('email', 'purchasing@audit.local')->first() ?? new User;
$purchasingUser->forceFill([
    'name' => 'مسؤول المشتريات',
    'email' => 'purchasing@audit.local',
    'password' => Hash::make($purchasePass),
    'role_id' => $purchaseRole->id,
    'is_active' => true,
    'auth_version' => 1,
])->save();

// 3. Sales user
$salesUser = User::where('email', 'sales@audit.local')->first() ?? new User;
$salesUser->forceFill([
    'name' => 'مسؤول المبيعات',
    'email' => 'sales@audit.local',
    'password' => Hash::make($salesPass),
    'role_id' => $salesRole->id,
    'is_active' => true,
    'auth_version' => 1,
])->save();

// Write credentials to untracked .cache/audit_env.json
$cacheDir = __DIR__.'/../../../../.cache';
if (! is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$credentials = [
    'admin' => ['email' => 'admin@audit.local', 'password' => $adminPass],
    'purchasing' => ['email' => 'purchasing@audit.local', 'password' => $purchasePass],
    'sales' => ['email' => 'sales@audit.local', 'password' => $salesPass],
];

file_put_contents($cacheDir.'/audit_env.json', json_encode($credentials, JSON_PRETTY_PRINT));

echo "Audit users configured successfully. Credentials saved to .cache/audit_env.json.\n";
