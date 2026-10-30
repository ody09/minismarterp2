@extends('layouts.app')
@section('title', $product->name)
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('inventory.batch_details') }}</span><h1>{{ $product->name }}</h1></div><a class="button" href="{{ route('inventory.index') }}">{{ __('inventory.back') }}</a></div>
<div class="stats-grid">@foreach(['physical_quantity' => 'physical', 'sellable_quantity' => 'sellable', 'expired_quantity' => 'expired'] as $field => $label)<section class="card stat"><span class="muted">{{ __('inventory.'.$label) }}</span><strong>{{ (int) ($product->$field ?? 0) }}</strong></section>@endforeach</div>
<section class="card"><div class="table-wrap"><table><thead><tr><th>{{ __('catalog.batch_number') }}</th><th>{{ __('catalog.expiration_date') }}</th><th>{{ __('inventory.physical') }}</th><th>{{ __('inventory.sellable') }}</th><th>{{ __('catalog.status') }}</th></tr></thead><tbody>
@forelse($batches as $batch)@php($expired = $batch->expiration_date && $batch->expiration_date->toDateString() < $today)<tr><td>{{ $batch->batch_number }}</td><td>{{ $batch->expiration_date?->format('Y-m-d') ?? __('catalog.no_expiry') }}</td><td>{{ (int) ($batch->physical_quantity ?? 0) }}</td><td>{{ $product->is_active && ! $expired ? (int) ($batch->physical_quantity ?? 0) : 0 }}</td><td><span class="pill {{ $expired ? 'inactive' : '' }}">{{ __($expired ? 'inventory.status_expired' : 'inventory.status_valid') }}</span></td></tr>@empty<tr><td colspan="5" class="empty-state">{{ __('inventory.empty_batches') }}</td></tr>@endforelse
</tbody></table></div>@include('partials.pagination', ['paginator' => $batches])</section>
<a class="button" href="{{ route('inventory.transactions', ['product_id' => $product->id]) }}">{{ __('inventory.transactions') }}</a>
@endsection
