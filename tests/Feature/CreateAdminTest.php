<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_bootstrap_creates_an_admin_without_a_default_password(): void
    {
        $this->artisan('erp:create-admin', ['email' => ' OWNER@example.test ', '--name' => 'Owner'])
            ->expectsQuestion('Password', 'SecurePassword123!')->expectsQuestion('Confirm password', 'SecurePassword123!')->assertSuccessful();
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame('admin', $user->role->code);
        $this->assertTrue(Hash::check('SecurePassword123!', $user->password));
    }

    public function test_console_bootstrap_rejects_weak_passwords(): void
    {
        $this->artisan('erp:create-admin', ['email' => 'owner@example.test', '--name' => 'Owner'])
            ->expectsQuestion('Password', 'short')->expectsQuestion('Confirm password', 'short')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
