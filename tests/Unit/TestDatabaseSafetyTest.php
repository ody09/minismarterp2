<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabaseSafety;

class TestDatabaseSafetyTest extends TestCase
{
    public function test_resolved_mysql_configuration_is_rejected_before_database_access(): void
    {
        $config = new Repository(['database' => ['default' => 'mysql', 'connections' => ['mysql' => ['database' => 'smart_mini_erp']]]]);
        $this->expectException(LogicException::class);
        TestDatabaseSafety::assertSafe($config);
    }

    public function test_a_file_database_is_rejected_even_when_sqlite_is_selected(): void
    {
        $config = new Repository(['database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['database' => 'database/application.sqlite']]]]);
        $this->expectException(LogicException::class);
        TestDatabaseSafety::assertSafe($config);
    }

    public function test_a_database_url_is_rejected_even_when_the_database_field_says_memory(): void
    {
        $config = new Repository(['database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['database' => ':memory:', 'url' => 'sqlite:///application.sqlite']]]]);
        $this->expectException(LogicException::class);
        TestDatabaseSafety::assertSafe($config);
    }

    public function test_only_sqlite_in_memory_is_accepted(): void
    {
        $config = new Repository(['database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['database' => ':memory:', 'url' => null]]]]);
        TestDatabaseSafety::assertSafe($config);
        $this->addToAssertionCount(1);
    }
}
