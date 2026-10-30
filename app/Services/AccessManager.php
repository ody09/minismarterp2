<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccessManager
{
    public function saveUser(User $actor, array $data, ?User $target = null): User
    {
        return DB::transaction(function () use ($actor, $data, $target) {
            $adminRole = $this->lockAdministration();
            $actor = $this->authorizedActor($actor, 'users.manage');
            $role = Role::findOrFail($data['role_id']);
            $this->assertRoleWithinAccess($actor, $role);
            $target = $target ? User::findOrFail($target->id) : new User;

            if ($target->exists) {
                $this->assertRoleWithinAccess($actor, $target->role);
                $removesAdmin = $target->role_id === $adminRole->id && $target->is_active
                    && ($role->id !== $adminRole->id || ! (bool) $data['is_active']);
                if ($removesAdmin && User::where('role_id', $adminRole->id)->where('is_active', true)->count() <= 1) {
                    throw ValidationException::withMessages(['role_id' => __('app.last_admin')]);
                }
            }
            if (User::where('email', $data['email'])->when($target->exists, fn ($query) => $query->where('id', '!=', $target->id))->exists()) {
                throw ValidationException::withMessages(['email' => __('validation.unique', ['attribute' => __('app.email')])]);
            }

            $target->forceFill([
                'name' => $data['name'], 'email' => $data['email'], 'role_id' => $role->id,
                'is_active' => (bool) $data['is_active'], 'auth_version' => $target->exists ? $target->auth_version + 1 : 1,
            ]);
            if (! empty($data['password'])) {
                $target->password = $data['password'];
                $target->remember_token = Str::random(60);
            }
            $target->save();

            return $target;
        }, 3);
    }

    public function saveRole(User $actor, array $data, ?Role $target = null): Role
    {
        return DB::transaction(function () use ($actor, $data, $target) {
            $this->lockAdministration();
            $actor = $this->authorizedActor($actor, 'roles.manage');
            $target = $target ? Role::findOrFail($target->id) : new Role;
            if ($target->is_system) {
                throw ValidationException::withMessages(['role' => __('app.protected_role')]);
            }
            if ($target->exists) {
                $this->assertRoleWithinAccess($actor, $target);
            }
            $codes = $data['permissions'] ?? [];
            $this->assertPermissionsWithinAccess($actor, $codes);
            if (Role::where('code', $data['code'])->when($target->exists, fn ($query) => $query->where('id', '!=', $target->id))->exists()) {
                throw ValidationException::withMessages(['code' => __('validation.unique', ['attribute' => __('app.code')])]);
            }
            $target->fill(['name' => $data['name'], 'code' => $data['code']])->save();
            $target->permissions()->sync(Permission::whereIn('code', $codes)->pluck('id'));

            return $target;
        }, 3);
    }

    public function deleteRole(User $actor, Role $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $this->lockAdministration();
            $actor = $this->authorizedActor($actor, 'roles.manage');
            $target = Role::findOrFail($target->id);
            if ($target->is_system) {
                throw ValidationException::withMessages(['role' => __('app.protected_role')]);
            }
            $this->assertRoleWithinAccess($actor, $target);
            if ($target->users()->exists()) {
                throw ValidationException::withMessages(['role' => __('app.role_in_use')]);
            }
            $target->delete();
        }, 3);
    }

    private function lockAdministration(): Role
    {
        // A stable lock serializes access changes, including concurrent removal of administrators.
        return Role::where('code', 'admin')->where('is_system', true)->lockForUpdate()->firstOrFail();
    }

    private function authorizedActor(User $actor, string $permission): User
    {
        $freshActor = User::findOrFail($actor->id);
        abort_unless($actor->auth_version === $freshActor->auth_version, 403);
        Gate::forUser($freshActor)->authorize($permission);

        return $freshActor;
    }

    private function assertRoleWithinAccess(User $actor, Role $role): void
    {
        abort_if($role->is_system && ! $actor->role->is_system, 403);
        $this->assertPermissionsWithinAccess($actor, $role->permissions()->pluck('code')->all());
    }

    private function assertPermissionsWithinAccess(User $actor, array $codes): void
    {
        abort_if(count(array_diff($codes, $actor->role->permissions()->pluck('code')->all())) > 0, 403);
    }
}
