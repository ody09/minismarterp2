<?php

namespace Database\Factories;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return ['name' => 'Operator', 'code' => fake()->unique()->bothify('role-########')];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Role $role) {
            $permission = Permission::firstOrCreate(['code' => 'dashboard.view']);
            $role->permissions()->sync([$permission->id]);
        });
    }
}
