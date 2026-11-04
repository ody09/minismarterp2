<?php

// Standalone: never use the memory-only PHPUnit TestCase with a MySQL connection.
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\EnvironmentFile;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$source = Dotenv::parse(file_get_contents($root.'/.env'));
if (($source['APP_ENV'] ?? '') !== 'local' || ($source['DB_CONNECTION'] ?? '') !== 'mysql' || is_file($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Installer verification requires uncached local MySQL configuration.');
}
$originalHash = hash_file('sha256', $root.'/.env');
$tag = bin2hex(random_bytes(8));
$database = '__erp_install_'.$tag;
$foreign = '__erp_foreign_'.$tag;
$directory = $root.'/.cache/install-tests/'.$tag;
mkdir($directory, 0700, true);
copy($root.'/.env.example', $directory.'/.env');
$file = new EnvironmentFile($directory.'/.env');
$settings = array_intersect_key($source, array_flip(['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD']));
$file->update($settings + ['DB_DATABASE' => $database, 'BCRYPT_ROUNDS' => '4']);
$server = new PDO('mysql:host='.$settings['DB_HOST'].';port='.$settings['DB_PORT'].';charset=utf8mb4', $settings['DB_USERNAME'], $settings['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$owned = [];
$app = null;
$status = 0;

function installAssert(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAIL '.$label);
    }
    echo 'PASS '.$label."\n";
}

try {
    foreach ([$database, $foreign] as $schema) {
        $check = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $check->execute([$schema]);
        installAssert(! $check->fetchColumn(), 'disposable_schema_is_absent');
        $server->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $owned[] = $schema;
    }
    $server->exec('CREATE TABLE `'.$foreign.'`.foreign_data (tag varchar(32) PRIMARY KEY)');
    $insert = $server->prepare('INSERT INTO `'.$foreign.'`.foreign_data VALUES (?)');
    $insert->execute([$tag]);
    $app = require $root.'/bootstrap/app.php';
    $app->useEnvironmentPath($directory);
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    installAssert(config('database.connections.mysql.database') === $database && empty(config('app.key')), 'separate_fresh_environment');
    $command = $kernel->all()['erp:install'];
    $run = function (array $inputs, array $arguments = []) use ($command): CommandTester {
        $tester = new CommandTester($command);
        $tester->setInputs($inputs);
        $tester->execute($arguments, ['interactive' => true]);

        return $tester;
    };
    $password = 'InstallerFixture123!';
    $before = file_get_contents($directory.'/.env');
    $tester = $run(['', '', '', '', 'yes', 'no']);
    installAssert($tester->getStatusCode() !== 0 && file_get_contents($directory.'/.env') === $before, 'cancellation_preserves_env');
    installAssert($server->query('SHOW TABLES FROM `'.$database.'`')->fetchAll() === [], 'cancellation_does_not_migrate');
    $tester = $run(['', '', '', '', 'yes', 'yes', 'Test Owner', 'owner@example.test', $password, $password]);
    // Only show captured output on failure; input passwords must never appear there.
    installAssert(! str_contains($tester->getDisplay(), $password) && ($settings['DB_PASSWORD'] === '' || ! str_contains($tester->getDisplay(), $settings['DB_PASSWORD'])), 'passwords_are_not_echoed');
    if ($tester->getStatusCode() !== 0) {
        echo $tester->getDisplay();
    }
    installAssert($tester->getStatusCode() === 0, 'fresh_install');
    $installed = Dotenv::parse(file_get_contents($directory.'/.env'));
    installAssert(str_starts_with($installed['APP_KEY'], 'base64:'), 'key_generated');
    $owner = User::where('email', 'owner@example.test')->firstOrFail();
    installAssert(Hash::check($password, $owner->password) && $owner->role->code === 'admin' && $owner->is_active, 'administrator_created');
    installAssert(Permission::count() === count(Permission::CODES) && Warehouse::count() === 1, 'base_data_seeded');
    $hash = $owner->password;
    Warehouse::where('code', 'main')->update(['name' => 'Owner warehouse']);
    $role = Role::create(['code' => 'installer_custom', 'name' => 'Custom']);
    $role->permissions()->sync(Permission::where('code', 'dashboard.view')->pluck('id'));
    $tester = $run(['yes']);
    installAssert($tester->getStatusCode() === 0 && str_contains($tester->getDisplay(), 'administrator preserved'), 'repeat_install');
    installAssert(Dotenv::parse(file_get_contents($directory.'/.env'))['APP_KEY'] === $installed['APP_KEY'] && User::count() === 1 && $owner->fresh()->password === $hash, 'key_and_admin_preserved');
    installAssert(Warehouse::count() === 1 && Warehouse::first()->name === 'Owner warehouse' && $role->permissions()->count() === 1, 'owner_data_and_custom_permissions_preserved');

    $before = file_get_contents($directory.'/.env');
    $tester = $run(['', '', '', '', 'no', 'IncorrectFixturePassword123!'], ['--configure' => true]);
    installAssert($tester->getStatusCode() !== 0 && str_contains($tester->getDisplay(), 'MySQL connection/setup failed'), 'bad_credentials_fail_safely');
    installAssert(file_get_contents($directory.'/.env') === $before && ! str_contains($tester->getDisplay(), 'IncorrectFixturePassword123!'), 'bad_credentials_preserve_env_and_hide_password');
    $tester = $run(['', '', $foreign, '', 'yes', 'yes'], ['--configure' => true]);
    installAssert($tester->getStatusCode() !== 0 && str_contains($tester->getDisplay(), 'Refusing a nonempty database'), 'unrelated_database_refused');
    installAssert(file_get_contents($directory.'/.env') === $before, 'refusal_preserves_env');
    installAssert($server->query('SELECT tag FROM `'.$foreign.'`.foreign_data')->fetchColumn() === $tag, 'foreign_data_preserved');

    $migrationOnly = '__erp_migrations_'.$tag;
    $check->execute([$migrationOnly]);
    installAssert(! $check->fetchColumn(), 'migration_only_schema_is_absent');
    $server->exec('CREATE DATABASE `'.$migrationOnly.'`');
    $owned[] = $migrationOnly;
    $server->exec('CREATE TABLE `'.$migrationOnly.'`.migrations (id int PRIMARY KEY, migration varchar(255), batch int)');
    $server->exec('INSERT INTO `'.$migrationOnly."`.migrations VALUES (1, 'unrelated_application', 1)");
    $tester = $run(['', '', $migrationOnly, '', 'yes', 'yes'], ['--configure' => true]);
    installAssert($tester->getStatusCode() !== 0 && str_contains($tester->getDisplay(), 'Refusing a nonempty database'), 'foreign_migration_only_database_refused');
    installAssert(file_get_contents($directory.'/.env') === $before && count($server->query('SHOW TABLES FROM `'.$migrationOnly.'`')->fetchAll()) === 1, 'foreign_migration_only_env_and_schema_preserved');

    $created = '__erp_created_'.$tag;
    $check->execute([$created]);
    installAssert(! $check->fetchColumn(), 'new_schema_is_absent');
    $owned[] = $created;
    $tester = $run(['', '', $created, '', 'yes', 'yes', 'New Owner', 'new@example.test', $password, $password], ['--configure' => true]);
    installAssert($tester->getStatusCode() === 0 && User::where('email', 'new@example.test')->exists(), 'missing_database_created_and_installed');
    installAssert(hash_file('sha256', $root.'/.env') === $originalHash, 'real_environment_untouched');
} catch (Throwable $exception) {
    fwrite(STDERR, str_starts_with($exception->getMessage(), 'FAIL ') ? $exception->getMessage()."\n" : 'FAIL installer verification ('.$exception::class.")\n");
    $status = 1;
} finally {
    if ($app !== null) {
        DB::disconnect('mysql');
    }
    foreach ($owned as $schema) {
        if (! preg_match('/\A__erp_(install|foreign|created|migrations)_'.preg_quote($tag, '/').'\z/', $schema)) {
            throw new RuntimeException('Refusing cleanup of an unowned schema.');
        }
        $server->exec('DROP DATABASE IF EXISTS `'.$schema.'`');
    }
    unlink($directory.'/.env');
}
exit($status);
