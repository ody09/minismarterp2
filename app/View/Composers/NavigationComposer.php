<?php

namespace App\View\Composers;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class NavigationComposer
{
    /**
     * Bind navigation data to the view.
     */
    public function compose(View $view): void
    {
        $user = Auth::user();
        if (! $user) {
            $view->with('navigation', [
                'brand_route' => 'login',
                'items' => [],
            ]);

            return;
        }

        $permissionCodes = ($user->is_active && $user->role_id)
            ? array_flip($user->role->permissions()->pluck('code')->all())
            : [];

        $has = fn (string $code): bool => isset($permissionCodes[$code]);

        $brandRoute = $has('dashboard.view') ? 'dashboard' : 'account';

        $items = [];

        // 1. Dashboard or Account Access
        if ($has('dashboard.view')) {
            $items[] = [
                'url' => route('dashboard'),
                'label' => __('app.dashboard'),
                'selected' => request()->routeIs('dashboard'),
            ];
        } else {
            $items[] = [
                'url' => route('account'),
                'label' => __('app.account_access'),
                'selected' => request()->routeIs('account'),
            ];
        }

        // 2. Users
        if ($has('users.view')) {
            $items[] = [
                'url' => route('users.index'),
                'label' => __('app.users'),
                'selected' => request()->routeIs('users.*'),
            ];
        } elseif ($has('users.manage')) {
            $items[] = [
                'url' => route('users.create'),
                'label' => __('app.create_user'),
                'selected' => false,
            ];
        }

        // 3. Roles
        if ($has('roles.view')) {
            $items[] = [
                'url' => route('roles.index'),
                'label' => __('app.roles'),
                'selected' => request()->routeIs('roles.*'),
            ];
        } elseif ($has('roles.manage')) {
            $items[] = [
                'url' => route('roles.create'),
                'label' => __('app.create_role'),
                'selected' => false,
            ];
        }

        // 4. Catalog modules
        foreach (['categories', 'products', 'batches', 'customers', 'suppliers', 'warehouses'] as $module) {
            if ($has($module.'.view')) {
                $items[] = [
                    'url' => route($module.'.index'),
                    'label' => __('catalog.'.$module),
                    'selected' => request()->routeIs($module.'.*'),
                ];
            } elseif ($has($module.'.manage')) {
                $route = $module === 'warehouses' ? 'warehouses.edit' : $module.'.create';
                $items[] = [
                    'url' => route($route),
                    'label' => __('catalog.'.$module),
                    'selected' => false,
                ];
            }
        }

        // 5. Inventory
        if ($has('inventory.view')) {
            $items[] = [
                'url' => route('inventory.index'),
                'label' => __('inventory.title'),
                'selected' => request()->routeIs('inventory.index', 'inventory.product'),
            ];
            $items[] = [
                'url' => route('inventory.transactions'),
                'label' => __('inventory.transactions'),
                'selected' => request()->routeIs('inventory.transactions', 'inventory.operation'),
            ];
        }

        // 6. Purchases
        if ($has('purchases.view')) {
            $items[] = [
                'url' => route('purchases.index'),
                'label' => __('purchases.title'),
                'selected' => request()->routeIs('purchases.*'),
            ];
        } elseif ($has('purchases.manage')) {
            $items[] = [
                'url' => route('purchases.create'),
                'label' => __('purchases.create'),
                'selected' => false,
            ];
        }

        // 7. Sales & Prices
        foreach (['sales', 'prices'] as $module) {
            if ($has($module.'.view')) {
                $items[] = [
                    'url' => route($module.'.index'),
                    'label' => __($module.'.title'),
                    'selected' => request()->routeIs($module.'.*'),
                ];
            } elseif ($has($module.'.manage')) {
                $items[] = [
                    'url' => route($module.'.create'),
                    'label' => __($module.'.create'),
                    'selected' => false,
                ];
            }
        }

        // 8. Reports
        if ($has('reports.sales') || $has('reports.purchases') || $has('reports.inventory')) {
            $items[] = [
                'url' => route('reports.index'),
                'label' => __('reports.title'),
                'selected' => request()->routeIs('reports.*'),
            ];
        }

        $view->with('navigation', [
            'brand_route' => $brandRoute,
            'items' => $items,
        ]);
    }
}
