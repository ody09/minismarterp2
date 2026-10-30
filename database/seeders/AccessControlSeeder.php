<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permission::CODES as $code) {
            Permission::firstOrCreate(['code' => $code]);
        }

        foreach (['admin' => 'Administrator', 'purchasing' => 'Purchasing', 'sales' => 'Sales', 'inventory' => 'Inventory'] as $code => $name) {
            $role = Role::firstOrCreate(['code' => $code], ['name' => $name]);
            if ($code === 'admin') {
                $role->forceFill(['is_system' => true])->save();
                $role->permissions()->sync(Permission::pluck('id'));
            } elseif ($role->wasRecentlyCreated) {
                $role->permissions()->sync(Permission::where('code', 'dashboard.view')->pluck('id'));
            }
        }
    }
}
