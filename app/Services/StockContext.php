<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StockContext
{
    public function lock(User $actor, array $permissions): Warehouse
    {
        // Match AccessManager before serializing document/stock transitions.
        Role::where('code', 'admin')->where('is_system', true)->lockForUpdate()->firstOrFail();
        $warehouse = Warehouse::where('code', 'main')->lockForUpdate()->firstOrFail();
        $fresh = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $granted = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $fresh->role_id)->whereIn('permissions.code', $permissions)
            ->lockForUpdate()->pluck('permissions.code')->all();
        Gate::forUser($fresh)->allowIf(fn (User $user) => $user->is_active && $user->auth_version === $actor->auth_version
            && array_diff($permissions, $granted) === []);

        return $warehouse;
    }
}
