@extends('layouts.app')
@section('title', __('app.account_access'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('app.workspace') }}</span><h1>{{ __('app.welcome', ['name' => auth()->user()->name]) }}</h1><p class="muted">{{ __('app.dashboard_hint') }}</p></div></div>
@include('partials.account-access')
@endsection
