<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_refuses_noninteractive_installation(): void
    {
        $this->artisan('erp:install', ['--no-interaction' => true])
            ->expectsOutputToContain('interactive terminal')->assertFailed();
    }

    public function test_refuses_production_even_with_interactive_input(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('erp:install')->expectsOutputToContain('local development')->assertFailed();
    }

    public function test_refuses_non_mysql_without_touching_the_database(): void
    {
        $this->app->instance('env', 'local');
        $this->artisan('erp:install')->expectsOutputToContain('requires MySQL')->assertFailed();
    }
}
