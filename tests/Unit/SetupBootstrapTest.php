<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class SetupBootstrapTest extends TestCase
{
    public function test_bootstrap_prepares_a_clone_without_vendor_and_preserves_existing_env(): void
    {
        $directory = sys_get_temp_dir().'/erp-bootstrap-'.bin2hex(random_bytes(8));
        mkdir($directory.'/scripts', 0700, true);
        copy(dirname(__DIR__, 2).'/scripts/setup.php', $directory.'/scripts/setup.php');
        file_put_contents($directory.'/.env.example', "APP_ENV=local\nAPP_KEY=\n");
        try {
            $process = new Process([PHP_BINARY, $directory.'/scripts/setup.php', 'prepare']);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertFileEquals($directory.'/.env.example', $directory.'/.env');
            file_put_contents($directory.'/.env', "APP_KEY=keep\nDB_PASSWORD=private\n");
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame("APP_KEY=keep\nDB_PASSWORD=private\n", file_get_contents($directory.'/.env'));
            $process = new Process([PHP_BINARY, $directory.'/scripts/setup.php', 'install']);
            $process->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('Run setup.cmd first', $process->getErrorOutput());

            mkdir($directory.'/vendor');
            file_put_contents($directory.'/vendor/autoload.php', '<?php');
            file_put_contents($directory.'/artisan', '<?php echo extension_loaded("pdo_mysql") ? "MYSQL READY" : "DRIVER MISSING";');
            $process = new Process([PHP_BINARY, $directory.'/scripts/setup.php', 'serve'], null,
                PHP_OS_FAMILY === 'Windows' ? ['PHP_INI_SCAN_DIR' => false] : null);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('MYSQL READY', $process->getOutput());
        } finally {
            foreach (['scripts/setup.php', '.env', '.env.example', 'artisan', 'vendor/autoload.php', '.cache/setup-php/extensions.ini'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            foreach (['.cache/setup-php', '.cache', 'scripts', 'vendor', ''] as $folder) {
                if (is_dir($directory.'/'.$folder)) {
                    rmdir($directory.'/'.$folder);
                }
            }
        }
    }
}
