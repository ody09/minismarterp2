<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    public const CODES = [
        'dashboard.view', 'users.view', 'users.manage', 'roles.view', 'roles.manage',
        'categories.view', 'categories.manage', 'products.view', 'products.manage',
        'batches.view', 'batches.manage', 'customers.view', 'customers.manage',
        'suppliers.view', 'suppliers.manage', 'warehouses.view', 'warehouses.manage',
        'inventory.view', 'inventory.post', 'inventory.reverse',
        'purchases.view', 'purchases.manage', 'purchases.receive',
    ];

    public $timestamps = false;

    protected $fillable = ['code'];
}
