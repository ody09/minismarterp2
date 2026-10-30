@extends('layouts.app')
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('app.workspace') }}</span><h1>{{ __('app.welcome', ['name' => auth()->user()->name]) }}</h1><p class="muted">{{ __('app.dashboard_hint') }}</p></div></div>
<div class="stats-grid">
    <section class="card stat"><span class="muted">{{ __('app.your_role') }}</span><strong>{{ auth()->user()->role->name }}</strong></section>
    <section class="card stat"><span class="muted">{{ __('app.your_access') }}</span><strong>{{ auth()->user()->role->permissions()->count() }}</strong></section>
    <section class="card stat"><span class="muted">{{ __('app.account_status') }}</span><strong class="teal">{{ __('app.active') }}</strong></section>
</div>
<section class="card"><h2>{{ __('app.account_access') }}</h2><div class="permission-list">@forelse(auth()->user()->role->permissions as $permission)<span class="pill">{{ __('app.permission_labels.'.$permission->code) }}</span>@empty<p>{{ __('app.no_permissions') }}</p>@endforelse</div></section>
@endsection
