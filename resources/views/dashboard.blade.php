@extends('layouts.app')
@section('title', __('reports.business'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('app.welcome', ['name' => auth()->user()->name]) }}</span><h1>{{ __('reports.business') }}</h1><p class="muted">{{ __('reports.hint') }}</p></div>@canany(['reports.sales','reports.purchases','reports.inventory'])<a class="button" href="{{ route('reports.index') }}">{{ __('reports.title') }}</a>@endcanany</div>
@if(isset($data['sales']) || isset($data['purchases']) || isset($data['inventory']))
<section class="card"><form method="get" class="purchase-filters">@include('reports.period', ['filters' => $data['filters']])
@if(isset($data['inventory']))<label for="expiry_days">{{ __('reports.expiry_days') }}<input type="number" id="expiry_days" name="expiry_days" min="1" max="365" step="1" value="{{ $data['filters']['expiry_days'] }}"></label>@endif<button class="button">{{ __('reports.apply') }}</button></form><p class="muted form-note">{{ __('reports.period_hint', ['timezone' => config('inventory.timezone')]) }}</p></section>
@foreach(['sales', 'purchases'] as $kind)@if(isset($data[$kind]))
<div class="page-heading"><h2>{{ __('reports.'.$kind.'_total') }}</h2><a href="{{ route('reports.'.$kind, $data['period']->filters) }}">{{ __('reports.open') }}</a></div>
@include('reports.summary', ['summary' => $data[$kind]['summary']])
<section class="card"><h2>{{ __('reports.recent') }}</h2>@include('reports.documents', ['kind' => $kind, 'orders' => $data[$kind]['orders'], 'paginated' => false])</section>
@endif @endforeach
@if(isset($data['inventory']))
<div class="page-heading"><h2>{{ __('reports.stock_now') }}</h2><a href="{{ route('reports.inventory', ['expiry_days' => $data['filters']['expiry_days']]) }}">{{ __('reports.open') }}</a></div>
@include('reports.stock-summary', ['summary' => $data['inventory']['summary'], 'filters' => $data['inventory']['filters']])
<section class="card"><h2>{{ __('reports.low_list') }}</h2>@include('reports.products', ['products' => $data['inventory']['products'], 'paginated' => false])</section>
<section class="card"><h2>{{ __('reports.expiry_list') }}</h2>@include('reports.batches', ['batches' => $data['inventory']['batches'], 'today' => $data['inventory']['today'], 'until' => $data['inventory']['until'], 'paginated' => false])</section>
@endif
@else<div class="notice">{{ __('reports.no_access') }}</div>@endif
<details class="account-panel"><summary>{{ __('app.account_access') }}</summary>@include('partials.account-access')</details>
@endsection
