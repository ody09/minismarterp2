<?php

namespace Tests\Support;

use Illuminate\Contracts\Config\Repository;
use LogicException;

class TestDatabaseSafety
{
    public static function assertSafe(Repository $config): void
    {
        if ($config->get('database.default') !== 'sqlite'
            || $config->get('database.connections.sqlite.database') !== ':memory:'
            || ! empty($config->get('database.connections.sqlite.url'))) {
            throw new LogicException('Tests require SQLite :memory:. Refusing database access before migrations. Clear the Laravel configuration cache with php artisan config:clear.');
        }
    }
}
