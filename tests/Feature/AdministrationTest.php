<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    private function user(string $role = 'sales', array $attributes = []): User
    {
        return User::factory()->create(['role_id' => Role::where('code', $role)->firstOrFail()->id, ...$attributes]);
    }

    private function signIn(User $user): static
    {
        $user->refresh();

        return $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
    }

    private function payload(array $overrides = []): array
    {
        return ['name' => 'New Operator', 'email' => 'new@example.test', 'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!', 'role_id' => Role::where('code', 'sales')->firstOrFail()->id,
            'is_active' => '1', ...$overrides];
    }

    public function test_inactive_accounts_cannot_sign_in(): void
    {
        $user = $this->user(attributes: ['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_accounts_without_overview_permission_have_a_safe_landing_page(): void
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(Permission::where('code', 'users.view')->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/account');
        $this->get('/account')->assertOk()->assertSee('/users', false);
        $this->get('/dashboard')->assertForbidden();
        $this->post('/locale', ['locale' => 'en'])->assertRedirect('/account');
        $this->get('/')->assertRedirect('/account');
    }

    public function test_management_only_roles_can_navigate_to_their_authorized_forms(): void
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(Permission::whereIn('code', ['users.manage', 'roles.manage'])->pluck('id'));
        $this->signIn(User::factory()->create(['role_id' => $role->id]))->get('/account')->assertOk()
            ->assertSee('/users/create', false)->assertSee('/roles/create', false);
        $this->get('/users/create')->assertOk();
        $this->get('/roles/create')->assertOk();
    }

    public function test_deactivation_invalidates_an_existing_session(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $this->get('/dashboard')->assertOk();
        User::whereKey($user->id)->update(['is_active' => false]);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_revoked_session_cannot_be_reused(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $this->get('/dashboard')->assertOk();
        User::whereKey($user->id)->increment('auth_version');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_sales_users_see_the_dashboard_without_administration_links(): void
    {
        $this->signIn($this->user())->get('/dashboard')->assertOk()->assertDontSee('/users', false)->assertDontSee('/roles', false);
    }

    public function test_unauthorized_users_cannot_read_or_forge_administration_requests(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $this->get('/users')->assertForbidden();
        $this->get('/roles')->assertForbidden();
        $this->post('/users', $this->payload())->assertForbidden();
        $this->patch('/users/'.$user->id, $this->payload())->assertForbidden();
        $this->post('/roles', ['name' => 'Escalated', 'code' => 'escalated', 'permissions' => Permission::CODES])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
        $this->assertDatabaseMissing('roles', ['code' => 'escalated']);
    }

    public function test_admin_can_search_users_and_open_administration_forms(): void
    {
        $this->user(attributes: ['name' => 'Match Operator', 'email' => 'match@example.test']);
        $this->user(attributes: ['name' => 'Other Operator']);
        $this->signIn($this->user('admin'))->get('/users?q=match')->assertOk()->assertSee('Match Operator')->assertDontSee('Other Operator');
        $this->get('/users/create')->assertOk();
        $this->get('/roles')->assertOk();
        $this->get('/roles/create')->assertOk();
    }

    public function test_admin_can_create_an_account_with_a_hashed_password_and_normalized_email(): void
    {
        $this->signIn($this->user('admin'))->post('/users', $this->payload(['email' => ' NEW@example.test ']))->assertRedirect('/users');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('SecurePassword123!', $user->password));
        $this->assertTrue($user->is_active);
        $this->assertSame('sales', $user->role->code);
    }

    public function test_invalid_user_input_does_not_create_an_account(): void
    {
        $this->signIn($this->user('admin'))->post('/users', $this->payload(['email' => 'invalid', 'password' => 'short', 'role_id' => 99999]))
            ->assertSessionHasErrors(['email', 'password', 'role_id']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_can_update_an_account_without_replacing_a_blank_password(): void
    {
        $target = $this->user();
        $password = $target->password;
        $this->signIn($this->user('admin'))->patch('/users/'.$target->id,
            $this->payload(['email' => $target->email, 'password' => '', 'password_confirmation' => '', 'is_active' => '0']))->assertRedirect('/users');
        $this->assertSame($password, $target->fresh()->password);
        $this->assertFalse($target->fresh()->is_active);
        $this->assertSame(2, $target->fresh()->auth_version);
    }

    public function test_password_replacement_revokes_existing_sessions(): void
    {
        $target = $this->user();
        $this->signIn($this->user('admin'))->patch('/users/'.$target->id, $this->payload(['email' => $target->email]))->assertRedirect('/users');
        $this->assertTrue(Hash::check('SecurePassword123!', $target->fresh()->password));
        $this->assertSame(2, $target->fresh()->auth_version);
    }

    public function test_last_active_administrator_cannot_be_demoted_or_disabled(): void
    {
        $admin = $this->user('admin');
        $this->signIn($admin)->patch('/users/'.$admin->id, $this->payload(['email' => $admin->email, 'is_active' => '0']))
            ->assertSessionHasErrors('role_id');
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertSame('admin', $admin->fresh()->role->code);
    }

    public function test_one_administrator_can_be_disabled_when_another_remains(): void
    {
        $target = $this->user('admin');
        $this->signIn($this->user('admin'))->patch('/users/'.$target->id,
            $this->payload(['email' => $target->email, 'role_id' => $target->role_id, 'is_active' => '0']))->assertRedirect('/users');
        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_user_managers_cannot_assign_a_more_privileged_role(): void
    {
        $managerRole = Role::factory()->create();
        $managerRole->permissions()->sync(Permission::whereIn('code', ['dashboard.view', 'users.manage'])->pluck('id'));
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $this->signIn($manager)->post('/users', $this->payload(['role_id' => Role::where('code', 'admin')->firstOrFail()->id]))->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_user_managers_cannot_take_over_a_privileged_account(): void
    {
        $target = $this->user('admin');
        $managerRole = Role::factory()->create();
        $managerRole->permissions()->sync(Permission::whereIn('code', ['dashboard.view', 'users.manage'])->pluck('id'));
        $this->signIn(User::factory()->create(['role_id' => $managerRole->id]))
            ->patch('/users/'.$target->id, $this->payload(['email' => $target->email]))->assertForbidden();
        $this->assertTrue(Hash::check('password', $target->fresh()->password));
    }

    public function test_admin_can_create_update_and_delete_an_unused_role(): void
    {
        $this->signIn($this->user('admin'))->post('/roles', ['name' => 'Auditor', 'code' => 'auditor', 'permissions' => ['dashboard.view', 'users.view']])->assertRedirect('/roles');
        $role = Role::where('code', 'auditor')->firstOrFail();
        $this->assertSame(['dashboard.view', 'users.view'], $role->permissions()->orderBy('code')->pluck('code')->all());
        $this->get('/roles/'.$role->id.'/edit')->assertOk();
        $this->patch('/roles/'.$role->id, ['name' => 'Reader', 'code' => 'reader', 'permissions' => ['dashboard.view']])->assertRedirect('/roles');
        $this->assertSame('Reader', $role->fresh()->name);
        $this->assertSame(1, $role->fresh()->permissions()->count());
        $this->delete('/roles/'.$role->id)->assertRedirect('/roles');
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_protected_and_assigned_roles_cannot_be_deleted(): void
    {
        $admin = $this->user('admin');
        $this->signIn($admin)->delete('/roles/'.$admin->role_id)->assertSessionHasErrors('role');
        $this->patch('/roles/'.$admin->role_id, ['name' => 'Changed', 'code' => 'changed', 'permissions' => []])->assertSessionHasErrors('role');
        $target = $this->user();
        $this->delete('/roles/'.$target->role_id)->assertSessionHasErrors('role');
        $this->assertDatabaseHas('roles', ['id' => $admin->role_id, 'code' => 'admin', 'is_system' => true]);
    }

    public function test_role_managers_cannot_grant_permissions_they_do_not_have(): void
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(Permission::whereIn('code', ['dashboard.view', 'roles.manage'])->pluck('id'));
        $this->signIn(User::factory()->create(['role_id' => $role->id]))
            ->post('/roles', ['name' => 'Escalated', 'code' => 'escalated', 'permissions' => ['users.manage']])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['code' => 'escalated']);
    }

    public function test_unknown_permissions_are_rejected(): void
    {
        $this->signIn($this->user('admin'))->post('/roles', ['name' => 'Invalid', 'code' => 'invalid', 'permissions' => ['unknown.permission']])
            ->assertSessionHasErrors('permissions.0');
    }

    public function test_user_names_are_escaped_in_the_interface(): void
    {
        $this->user(attributes: ['name' => '<script>alert(1)</script>']);
        $this->signIn($this->user('admin'))->get('/users')->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_seeding_does_not_create_accounts_or_reset_custom_role_permissions(): void
    {
        $role = Role::where('code', 'sales')->firstOrFail();
        $role->permissions()->sync([]);
        $this->seed(AccessControlSeeder::class);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(0, $role->permissions()->count());
        $this->assertDatabaseCount('roles', 4);
        $this->assertDatabaseCount('permissions', 23);
    }
}
