<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$config = config('database.connections.mysql');
$host = (string) ($config['host'] ?? '127.0.0.1');
$port = (int) ($config['port'] ?? 3306);
$database = (string) ($config['database'] ?? 'smart_mini_erp');
$username = (string) ($config['username'] ?? 'smarterp');
$password = (string) ($config['password'] ?? '');

if ($port !== 3306) {
    throw new RuntimeException("Expected MySQL port 3306, found {$port} in configuration.");
}
if ($database === 'smart_mini_erp_test' || $database === 'smart_mini_erp_perf') {
    throw new RuntimeException("Refusing to use test database '{$database}' for daily operations.");
}

// 1. Ensure database exists and user has access
try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException) {
    // If connection as configured user fails, attempt connecting as root to provision user/db
    try {
        $rootPdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $rootPdo->exec("CREATE USER IF NOT EXISTS '{$username}'@'127.0.0.1' IDENTIFIED BY '{$password}';");
        $rootPdo->exec("CREATE USER IF NOT EXISTS '{$username}'@'localhost' IDENTIFIED BY '{$password}';");
        $rootPdo->exec("GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$username}'@'127.0.0.1';");
        $rootPdo->exec("GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$username}'@'localhost';");
        $rootPdo->exec('FLUSH PRIVILEGES;');
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (PDOException $rootException) {
        throw new RuntimeException("Database connection failed for '{$username}' and root provisioning failed: ".$rootException->getMessage());
    }
}

// Verify target database
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
$pdo->exec("USE `{$database}`;");

// 2. Run migrations
$migrateCode = Artisan::call('migrate', ['--force' => true]);
if ($migrateCode !== 0) {
    throw new RuntimeException('Database migration failed: '.Artisan::output());
}

// 3. Run seeders
$seedCode = Artisan::call('db:seed', ['--force' => true]);
if ($seedCode !== 0) {
    throw new RuntimeException('Database seeding failed: '.Artisan::output());
}

// 4. Ensure an active administrator exists without altering existing passwords
$adminRole = Role::where('code', 'admin')->first();
if (! $adminRole) {
    Artisan::call('db:seed', ['--class' => AccessControlSeeder::class, '--force' => true]);
    $adminRole = Role::where('code', 'admin')->firstOrFail();
}

$hasActiveAdmin = User::where('is_active', true)
    ->whereHas('role', fn ($q) => $q->where('code', 'admin'))
    ->exists();

if (! $hasActiveAdmin) {
    $defaultEmail = (string) (env('INITIAL_ADMIN_EMAIL') ?: 'admin@minismarterp.local');
    $initialPass = (string) (env('INITIAL_ADMIN_PASSWORD') ?: bin2hex(random_bytes(10)).'!Aa1');
    $user = User::where('email', $defaultEmail)->first() ?? new User;
    $user->forceFill([
        'name' => 'System Administrator',
        'email' => $defaultEmail,
        'password' => Hash::make($initialPass),
        'role_id' => $adminRole->id,
        'is_active' => true,
    ])->save();

    $credsFile = $root.'/.cache/daily_admin_credentials.txt';
    @file_put_contents($credsFile, "Email: {$defaultEmail}\nPassword: {$initialPass}\n");
    echo "[SETUP] Created initial administrator: {$defaultEmail}\n";
    echo "[SETUP] Temporary credentials saved to ignored file: .cache/daily_admin_credentials.txt\n";
} else {
    echo "[OK] Existing administrator and credentials preserved.\n";
}

echo "[OK] Daily database connection ready.\n";
