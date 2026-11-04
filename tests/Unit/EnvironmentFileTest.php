<?php

namespace Tests\Unit;

use App\Support\EnvironmentFile;
use Dotenv\Dotenv;
use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;

class EnvironmentFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'erp-env-');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    public function test_updates_literal_values_without_changing_other_settings(): void
    {
        file_put_contents($this->path, "# Local settings\nAPP_KEY=base64:existing\nDB_PASSWORD=old\nDB_PASSWORD=duplicate\nMAIL_HOST=localhost\n");
        $password = ' spaces # $HOME ${APP_KEY} "quotes" \'single\' \\ trailing ';

        (new EnvironmentFile($this->path))->update(['DB_PASSWORD' => $password, 'DB_DATABASE' => 'new_erp']);

        $contents = file_get_contents($this->path);
        $values = Dotenv::parse($contents);
        $this->assertSame($password, $values['DB_PASSWORD']);
        $this->assertSame('new_erp', $values['DB_DATABASE']);
        $this->assertSame('base64:existing', $values['APP_KEY']);
        $this->assertSame('localhost', $values['MAIL_HOST']);
        $this->assertStringContainsString('# Local settings', $contents);
        $this->assertSame(1, substr_count($contents, 'DB_PASSWORD='));
    }

    public function test_rejects_newline_injection_before_writing(): void
    {
        $original = "APP_KEY=keep\nDB_PASSWORD=old\n";
        file_put_contents($this->path, $original);
        try {
            (new EnvironmentFile($this->path))->update(['DB_PASSWORD' => "secret\nAPP_DEBUG=true"]);
            $this->fail('Multiline input must be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame($original, file_get_contents($this->path));
        }
    }

    public function test_laravel_reads_reserved_and_quoted_strings_as_literal_credentials(): void
    {
        $values = [];
        foreach (['null', '(NULL)', 'false', '(true)', 'empty', '"quoted"', "'quoted'", ''] as $index => $value) {
            $values['ERP_LITERAL_'.strtoupper(bin2hex(random_bytes(8))).'_'.$index] = $value;
        }
        try {
            (new EnvironmentFile($this->path))->update($values);
            Dotenv::create(Env::getRepository(), dirname($this->path), basename($this->path))->load();
            foreach ($values as $key => $value) {
                $this->assertSame($value, Env::get($key));
            }
        } finally {
            foreach (array_keys($values) as $key) {
                Env::getRepository()->clear($key);
            }
        }
    }
}
