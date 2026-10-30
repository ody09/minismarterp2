@extends('layouts.app')
@section('title', __('app.sign_in'))
@section('content')
<div class="login-layout">
    <section class="login-intro"><span class="eyebrow">SMART MINI ERP</span><h1>{{ __('app.tagline') }}</h1><div class="intro-grid" aria-hidden="true"><span></span><span></span><span></span><span></span></div></section>
    <section class="card login-card"><div class="brand-icon">S</div><h2>{{ __('app.sign_in') }}</h2><p class="muted">{{ __('app.sign_in_hint') }}</p>
        <form action="{{ route('login') }}" method="post" class="stack">@csrf
            <label for="email">{{ __('app.email') }}<input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus dir="ltr" maxlength="255"></label>
            <label for="password">{{ __('app.password') }}<input id="password" name="password" type="password" autocomplete="current-password" required maxlength="255"></label>
            <button class="button primary">{{ __('app.sign_in') }} <span aria-hidden="true">↗</span></button>
        </form>
    </section>
</div>
@endsection
