<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('app.dashboard')) · Smart Mini ERP</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="{{ auth()->check() ? 'workspace' : 'guest' }}">
@auth
    <aside class="sidebar">
        <a class="brand" href="{{ route(auth()->user()->landingRouteName()) }}"><span class="brand-icon">S</span><span>Smart Mini <strong>ERP</strong></span></a>
        <div class="nav-label">{{ __('app.workspace') }}</div>
        <nav aria-label="{{ __('app.workspace') }}">
            @can('dashboard.view')<a class="{{ request()->routeIs('dashboard') ? 'selected' : '' }}" href="{{ route('dashboard') }}">{{ __('app.dashboard') }}</a>@else<a class="{{ request()->routeIs('account') ? 'selected' : '' }}" href="{{ route('account') }}">{{ __('app.account_access') }}</a>@endcan
            @can('users.view')<a class="{{ request()->routeIs('users.*') ? 'selected' : '' }}" href="{{ route('users.index') }}">{{ __('app.users') }}</a>@else @can('users.manage')<a href="{{ route('users.create') }}">{{ __('app.create_user') }}</a>@endcan @endcan
            @can('roles.view')<a class="{{ request()->routeIs('roles.*') ? 'selected' : '' }}" href="{{ route('roles.index') }}">{{ __('app.roles') }}</a>@else @can('roles.manage')<a href="{{ route('roles.create') }}">{{ __('app.create_role') }}</a>@endcan @endcan
            @foreach(['categories', 'products', 'batches', 'customers', 'suppliers', 'warehouses'] as $module)
                @can($module.'.view')<a class="{{ request()->routeIs($module.'.*') ? 'selected' : '' }}" href="{{ route($module.'.index') }}">{{ __('catalog.'.$module) }}</a>
                @else @can($module.'.manage')<a href="{{ route($module.($module === 'warehouses' ? '.edit' : '.create')) }}">{{ __('catalog.'.$module) }}</a>@endcan @endcan
            @endforeach
            @can('inventory.view')
                <a class="{{ request()->routeIs('inventory.index', 'inventory.product') ? 'selected' : '' }}" href="{{ route('inventory.index') }}">{{ __('inventory.title') }}</a>
                <a class="{{ request()->routeIs('inventory.transactions', 'inventory.operation') ? 'selected' : '' }}" href="{{ route('inventory.transactions') }}">{{ __('inventory.transactions') }}</a>
            @endcan
            @can('purchases.view')<a class="{{ request()->routeIs('purchases.*') ? 'selected' : '' }}" href="{{ route('purchases.index') }}">{{ __('purchases.title') }}</a>@else @can('purchases.manage')<a href="{{ route('purchases.create') }}">{{ __('purchases.create') }}</a>@endcan @endcan
        </nav>
        <div class="sidebar-footer"><span class="status-dot"></span>{{ __('app.active') }}</div>
    </aside>
@endauth
<div class="page">
    <header class="topbar">
        <span class="muted">{{ auth()->check() ? __('app.workspace') : 'Smart Mini ERP' }}</span>
        <div class="toolbar">
            <form action="{{ route('locale') }}" method="post" class="language-switch">@csrf
                <button name="locale" value="ar" lang="ar" class="{{ app()->getLocale() === 'ar' ? 'current' : '' }}">العربية</button>
                <button name="locale" value="en" lang="en" class="{{ app()->getLocale() === 'en' ? 'current' : '' }}">English</button>
            </form>
            @auth<form action="{{ route('logout') }}" method="post">@csrf<button class="link-button">{{ __('app.logout') }}</button></form>@endauth
        </div>
    </header>
    <main id="main" class="content">
        @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice error" role="alert"><strong>{{ __('app.validation_heading') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
</div>
<script src="{{ asset('js/confirm-delete.js') }}" defer></script>
</body>
</html>
