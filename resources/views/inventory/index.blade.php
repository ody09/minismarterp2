@extends('layouts.app')
@section('title', __('inventory.title'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('catalog.warehouses') }}</span><h1>{{ __('inventory.title') }}</h1><p class="muted">{{ __('inventory.hint') }}</p></div><a class="button" href="{{ route('inventory.transactions') }}">{{ __('inventory.transactions') }}</a></div>
<section class="card"><form class="search-bar" method="get"><label class="search-field" for="q"><span class="sr-only">{{ __('catalog.search_name') }}</span><input id="q" type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('catalog.search_name') }}"></label><button class="button">{{ __('app.search') }}</button></form>
<div class="table-wrap"><table><thead><tr><th>{{ __('catalog.product') }}</th><th>{{ __('inventory.physical') }}</th><th>{{ __('inventory.sellable') }}</th><th>{{ __('inventory.expired') }}</th><th>{{ __('catalog.conversion') }}</th><th>{{ __('catalog.status') }}</th><th>{{ __('app.actions') }}</th></tr></thead><tbody>
@forelse($products as $product)<tr><td><strong>{{ $product->name }}</strong></td><td>{{ (int) ($product->physical_quantity ?? 0) }}</td><td>{{ (int) ($product->sellable_quantity ?? 0) }}</td><td>{{ (int) ($product->expired_quantity ?? 0) }}</td><td>{{ __('catalog.conversion_text', ['pieces' => $product->pieces_per_carton]) }}</td><td>@include('partials.record-status', ['record' => $product])</td><td><a href="{{ route('inventory.product', $product) }}">{{ __('inventory.batch_details') }}</a></td></tr>@empty<tr><td colspan="7" class="empty-state">{{ __('inventory.empty_products') }}</td></tr>@endforelse
</tbody></table></div>@include('partials.pagination', ['paginator' => $products])<p class="muted form-note">{{ __('inventory.quantity') }}. {{ __('inventory.expiry_hint') }}</p></section>
<p class="muted">{{ __('inventory.zero_hint') }}</p>
@endsection
