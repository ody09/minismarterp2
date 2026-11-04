<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EnvironmentFile;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Question\Question;
use Throwable;

class InstallErp extends Command
{
    protected $signature = 'erp:install {--configure : Prompt again for the MySQL connection}';

    protected $description = 'Interactively prepare a local MySQL installation without resetting existing data';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run setup in an interactive terminal; passwords have no defaults.');

            return self::FAILURE;
        }
        if (! app()->environment('local')) {
            $this->error('This installer is for local development only.');

            return self::FAILURE;
        }
        if (config('database.default') !== 'mysql') {
            $this->error('This installer requires MySQL. Set DB_CONNECTION=mysql in .env.');

            return self::FAILURE;
        }
        try {
            $this->install();

            return self::SUCCESS;
        } catch (PDOException $exception) {
            // PDO messages can include connection details; never render them or their trace.
            $this->error('MySQL connection/setup failed (SQLSTATE '.$exception->getCode().'). Check MySQL, credentials and CREATE/ALTER permissions. To change settings: php scripts/setup.php install --configure');
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
        } catch (Throwable) {
            $this->error('Setup stopped. Check PHP/MySQL compatibility and writable storage/bootstrap/cache, then rerun .\setup.cmd. Existing data was not reset.');
        }

        return self::FAILURE;
    }

    private function install(): void
    {
        if (app()->configurationIsCached()) {
            throw new RuntimeException('Run php artisan config:clear before using the installer.');
        }
        $path = app()->environmentFilePath();
        if (! is_file($path)) {
            throw new RuntimeException('Missing .env. Run .\setup.cmd first.');
        }
        $env = Dotenv::parse(file_get_contents($path));
        $connection = config('database.connections.mysql');
        if (($env['APP_ENV'] ?? '') !== 'local' || ! empty($connection['url']) || ! empty($connection['unix_socket']) || ! empty($connection['prefix'])) {
            throw new RuntimeException('Use APP_ENV=local and a direct MySQL connection without DB_URL, DB_SOCKET or a table prefix.');
        }
        if (empty($env['APP_KEY']) || $this->option('configure')) {
            $connection['host'] = $this->ask('MySQL host', $connection['host']);
            $connection['port'] = $this->ask('MySQL port', (string) $connection['port']);
            $connection['database'] = $this->ask('Database name', $connection['database']);
            $connection['username'] = $this->ask('MySQL username', $connection['username']);
            if (empty($connection['password']) || ! $this->confirm('Keep the saved MySQL password?', true)) {
                $question = (new Question('MySQL password (empty is allowed)'))
                    ->setHidden(true)->setHiddenFallback(false)->setTrimmable(false);
                $connection['password'] = rtrim($this->output->askQuestion($question) ?? '', "\r\n");
            }
        }
        if (! preg_match('/\A[a-zA-Z0-9_.:\-]+\z/', (string) $connection['host'])
            || ! ctype_digit((string) $connection['port']) || $connection['port'] < 1 || $connection['port'] > 65535
            || ! preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', (string) $connection['database'])
            || ! is_string($connection['username']) || $connection['username'] === ''
            || preg_match('/[\x00-\x1f\x7f]/', $connection['username'].$connection['password'])) {
            throw new RuntimeException('Invalid MySQL settings. Database names allow only letters, digits and underscores (1..64).');
        }
        $server = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4',
            $connection['username'], $connection['password'], ($connection['options'] ?? []) + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = $server->query('SELECT VERSION()')->fetchColumn();
        if (stripos($version, 'mariadb') !== false || version_compare($version, '8.4', '<')) {
            throw new RuntimeException('MySQL 8.4 or later is required.');
        }
        $database = $connection['database'];
        $this->line('Target: '.$connection['host'].':'.$connection['port'].' / '.$database.' (user '.$connection['username'].')');
        if (! $this->confirm('Prepare this database? Existing ERP data and passwords will be preserved.', false)) {
            throw new RuntimeException('Setup cancelled.');
        }
        $exists = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$database]);
        if (! $exists->fetchColumn()) {
            $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        $server->exec('USE `'.$database.'`');
        $tables = $server->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $emptyMigrationTable = $tables === ['migrations'] && (int) $server->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 0;
        if ($tables !== [] && ! $emptyMigrationTable) {
            if (! in_array('migrations', $tables, true) || ! in_array('roles', $tables, true)
                || ! in_array('role_permissions', $tables, true) || ! in_array('permissions', $tables, true)) {
                throw new RuntimeException('Refusing a nonempty database that is not a recognized Smart Mini ERP installation. Choose a new empty database.');
            }
            $migrations = $server->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
            $known = array_map(fn ($file) => basename($file, '.php'), glob(database_path('migrations/*.php')));
            if (! in_array('0000_01_01_000000_create_access_tables', $migrations, true) || array_diff($migrations, $known)) {
                throw new RuntimeException('Unrecognized database migrations. Choose a new empty database.');
            }
        }
        $settings = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $database, 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password']];
        if (empty($env['APP_KEY'])) {
            $settings['APP_KEY'] = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
        }
        (new EnvironmentFile($path))->update($settings);
        config(['database.connections.mysql' => $connection]);
        DB::purge('mysql');
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS
            || $this->call('db:seed', ['--force' => true]) !== self::SUCCESS) {
            throw new RuntimeException('Migrations/seeding did not finish. Resolve the reported error and rerun setup.');
        }
        if (User::where('is_active', true)->whereHas('role', fn ($query) => $query->where('code', 'admin')->where('is_system', true))->exists()) {
            $this->info('Existing active administrator preserved.');
        } else {
            $name = $this->ask('Administrator name', 'Administrator');
            $email = $this->ask('Administrator email');
            $this->line('Choose at least 12 characters, including uppercase/lowercase letters and numbers.');
            if ($this->call('erp:create-admin', ['email' => $email ?? '', '--name' => $name]) !== self::SUCCESS) {
                throw new RuntimeException('Administrator was not created. Rerun setup to finish.');
            }
        }
        $this->info('Setup complete. Run composer dev, then open http://127.0.0.1:8000/login');
    }
}
