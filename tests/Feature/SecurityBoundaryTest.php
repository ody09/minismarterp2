<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityBoundaryTest extends TestCase
{
    public function test_login_requires_a_csrf_token_outside_the_test_environment(): void
    {
        $this->app->instance('env', 'local');
        $this->post('/login', ['email' => 'operator@example.test', 'password' => 'incorrect'])->assertStatus(419);
    }

    public function test_changing_language_requires_a_csrf_token(): void
    {
        $this->app->instance('env', 'local');
        $this->post('/locale', ['locale' => 'en'])->assertStatus(419);
    }
}
