<?php

// This entry point must run before vendor/ exists. No global PHP settings change.
$root = dirname(__DIR__);
chdir($root);
$mode = $argv[1] ?? '';

function runSetupProcess(array $arguments): int
{
    $process = proc_open($arguments, [STDIN, STDOUT, STDERR], $pipes);

    return is_resource($process) ? proc_close($process) : 1;
}

try {
    if (PHP_VERSION_ID < 80400 || ! function_exists('proc_open')) {
        throw new RuntimeException('Install PHP 8.4+ on PATH with proc_open enabled.');
    }
    if (! in_array($mode, ['prepare', 'install', 'serve'], true)) {
        throw new RuntimeException('Use setup.cmd or composer dev.');
    }
    if (PHP_OS_FAMILY === 'Windows' && ! extension_loaded('pdo_mysql')) {
        $scan = $root.'/.cache/setup-php';
        if (! is_dir($scan) && ! mkdir($scan, 0700, true)) {
            throw new RuntimeException('Cannot create the local PHP configuration directory.');
        }
        if (file_put_contents($scan.'/extensions.ini', "extension=pdo_mysql\n") === false) {
            throw new RuntimeException('Cannot write the local PHP configuration.');
        }
        putenv('PHP_INI_SCAN_DIR='.getenv('PHP_INI_SCAN_DIR').PATH_SEPARATOR.$scan);
    }
    if ($mode === 'prepare') {
        if (runSetupProcess([PHP_BINARY, '-r', 'exit(extension_loaded("pdo_mysql") ? 0 : 1);']) !== 0) {
            throw new RuntimeException('Enable pdo_mysql in PHP. On Windows, check extension_dir points to the PHP ext folder.');
        }
        if (! is_file($root.'/.env') && ! copy($root.'/.env.example', $root.'/.env')) {
            throw new RuntimeException('Cannot create .env. Check folder permissions.');
        }
        echo "PHP/MySQL driver ready. Existing .env preserved.\n";
        exit(0);
    }
    if (! is_file($root.'/vendor/autoload.php') || ! is_file($root.'/.env')) {
        throw new RuntimeException('Run setup.cmd first.');
    }
    if ($mode === 'serve') {
        exit(runSetupProcess([PHP_BINARY, $root.'/artisan', 'serve', '--host=127.0.0.1', '--port=8000', '--no-reload']));
    }
    $result = runSetupProcess([PHP_BINARY, $root.'/artisan', 'config:clear']);
    if ($result !== 0) {
        exit($result);
    }
    $arguments = array_slice($argv, 2);
    if (array_diff($arguments, ['--configure'])) {
        throw new RuntimeException('The only supported setup option is --configure.');
    }
    exit(runSetupProcess([PHP_BINARY, $root.'/artisan', 'erp:install', ...$arguments]));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
