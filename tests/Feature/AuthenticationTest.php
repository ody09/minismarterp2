<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_the_dashboard_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_login_page_is_available_without_authentication(): void
    {
        $this->get('/login')->assertOk()->assertSee('Smart Mini ERP');
    }

    public function test_valid_credentials_authenticate_and_normalize_the_email(): void
    {
        $user = User::factory()->create(['email' => 'operator@example.test']);

        $this->post('/login', ['email' => ' OPERATOR@example.test ', 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_are_rejected_without_flashing_the_password(): void
    {
        User::factory()->create(['email' => 'operator@example.test']);

        $this->from('/login')->post('/login', ['email' => 'operator@example.test', 'password' => 'incorrect'])
            ->assertRedirect('/login')->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');

        $this->assertGuest();
    }

    public function test_logout_ends_the_authenticated_session(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_repeated_failed_logins_are_throttled(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'missing@example.test', 'password' => 'incorrect']);
        }

        $this->post('/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])->assertStatus(429);
    }

    public function test_public_registration_is_unavailable(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'intruder@example.test', 'password' => 'password'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_one_accounts_rate_limit_does_not_lock_out_other_accounts_at_the_same_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'missing@example.test', 'password' => 'incorrect']);
        }
        $this->post('/login', ['email' => 'another@example.test', 'password' => 'incorrect'])
            ->assertSessionHasErrors('email')->assertStatus(302);
    }

    public function test_factory_passwords_are_hashed(): void
    {
        $user = User::factory()->create();
        $this->assertNotSame('password', $user->password);
        $this->assertTrue(Hash::check('password', $user->password));
    }
}
