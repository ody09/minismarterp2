@extends('layouts.app')
@section('title', __('reports.inventory'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('reports.title') }}</span><h1>{{ __('reports.inventory') }}</h1><p class="muted">{{ __('reports.inventory_hint') }}</p></div><a class="button" href="{{ route('reports.index') }}">{{ __('reports.title') }}</a></div>
<section class="card"><form method="get" class="purchase-filters">
<label for="q">{{ __('app.search') }}<input type="search" id="q" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
<label for="product_id">{{ __('catalog.product') }}<select id="product_id" name="product_id"><option value="">{{ __('prices.all_products') }}</option>@foreach($choices as $product)<option value="{{ $product->id }}" @selected((string)($filters['product_id'] ?? '') === (string)$product->id)>{{ $product->name }}</option>@endforeach</select></label>
<label for="alert">{{ __('reports.alert') }}<select id="alert" name="alert">@foreach(['all', 'low', 'near', 'expired'] as $alert)<option value="{{ $alert }}" @selected($filters['alert'] === $alert)>{{ __('reports.alert_'.$alert) }}</option>@endforeach</select></label>
<label for="expiry_days">{{ __('reports.expiry_days') }}<input type="number" id="expiry_days" name="expiry_days" min="1" max="365" step="1" value="{{ $filters['expiry_days'] }}"></label><button class="button">{{ __('reports.apply') }}</button>
</form><p class="muted form-note">{{ __('reports.stock_rule', ['today' => $today, 'until' => $until]) }}</p></section>
@include('reports.stock-summary')
<section class="card"><h2>{{ __('reports.product_balances') }}</h2>@include('reports.products', ['paginated' => true])</section>
<section class="card"><h2>{{ __('reports.batch_balances') }}</h2>@include('reports.batches', ['paginated' => true])</section>
@endsection
