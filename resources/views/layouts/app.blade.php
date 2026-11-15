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
        <a class="brand" href="{{ route($navigation['brand_route']) }}"><span class="brand-icon">S</span><span>Smart Mini <strong>ERP</strong></span></a>
        <div class="nav-label">{{ __('app.workspace') }}</div>
        <nav aria-label="{{ __('app.workspace') }}">
            @foreach($navigation['items'] as $item)
                <a class="{{ $item['selected'] ? 'selected' : '' }}" href="{{ $item['url'] }}">{{ $item['label'] }}</a>
            @endforeach
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
