<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProductBatchController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->user()?->landingRouteName() ?? 'login'));
Route::post('/locale', LocaleController::class)->name('locale');
Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login');
});
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/purchases', [PurchaseOrderController::class, 'index'])->middleware('can:purchases.view')->name('purchases.index');
    Route::middleware('can:purchases.manage')->group(function () {
        Route::get('/purchases/create', [PurchaseOrderController::class, 'create'])->name('purchases.create');
        Route::post('/purchases', [PurchaseOrderController::class, 'store'])->name('purchases.store');
        Route::get('/purchases/{purchase}/edit', [PurchaseOrderController::class, 'edit'])->name('purchases.edit');
        Route::patch('/purchases/{purchase}', [PurchaseOrderController::class, 'update'])->name('purchases.update');
        Route::post('/purchases/{purchase}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchases.cancel');
    });
    Route::middleware(['can:purchases.receive', 'can:inventory.post'])->group(function () {
        Route::get('/purchases/{purchase}/receive', [PurchaseOrderController::class, 'receipt'])->name('purchases.receipt');
        Route::post('/purchases/{purchase}/receive', [PurchaseOrderController::class, 'receive'])->name('purchases.receive');
    });
    Route::get('/purchases/{purchase}', [PurchaseOrderController::class, 'show'])->middleware('can:purchases.view')->name('purchases.show');
    Route::view('/dashboard', 'dashboard')->middleware('can:dashboard.view')->name('dashboard');
    Route::view('/account', 'dashboard')->name('account');
    Route::get('/users', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
    Route::resource('users', UserController::class)->only(['create', 'store', 'edit', 'update'])->middleware('can:users.manage');
    Route::get('/roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
    Route::resource('roles', RoleController::class)->only(['create', 'store', 'edit', 'update', 'destroy'])->middleware('can:roles.manage');
    foreach (['categories' => CategoryController::class, 'products' => ProductController::class,
        'batches' => ProductBatchController::class, 'customers' => CustomerController::class, 'suppliers' => SupplierController::class] as $resource => $controller) {
        Route::get('/'.$resource, [$controller, 'index'])->middleware('can:'.$resource.'.view')->name($resource.'.index');
        $routes = Route::resource($resource, $controller)->only(['create', 'store', 'edit', 'update', 'destroy'])->middleware('can:'.$resource.'.manage');
        if (in_array($resource, ['customers', 'suppliers'], true)) {
            $routes->parameters([$resource => 'contact']);
        }
    }
    Route::get('/warehouses', [WarehouseController::class, 'index'])->middleware('can:warehouses.view')->name('warehouses.index');
    Route::get('/warehouses/edit', [WarehouseController::class, 'edit'])->middleware('can:warehouses.manage')->name('warehouses.edit');
    Route::patch('/warehouses', [WarehouseController::class, 'update'])->middleware('can:warehouses.manage')->name('warehouses.update');
    Route::middleware('can:inventory.view')->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('/inventory/products/{product}', [InventoryController::class, 'product'])->name('inventory.product');
        Route::get('/inventory/transactions', [InventoryController::class, 'transactions'])->name('inventory.transactions');
        Route::get('/inventory/operations/{operation}', [InventoryController::class, 'operation'])->name('inventory.operation');
    });
});
