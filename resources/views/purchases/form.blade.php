@extends('layouts.app')
@section('title', __($order->exists ? 'purchases.edit' : 'purchases.create'))
@section('content')
@php
    $lines = old('items', $order->exists ? $order->items->map->only(['product_id', 'quantity', 'unit', 'unit_price'])->all() : [[]]);
    $lines = is_array($lines) ? array_slice(array_values($lines), 0, 100) : [[]];
    $lines = array_map(fn($line) => is_array($line) ? $line : [], $lines ?: [[]]);
@endphp
<div class="page-heading"><div><span class="eyebrow">{{ __('purchases.title') }}{{ $order->exists ? ' · '.$order->number() : '' }}</span><h1>{{ __($order->exists ? 'purchases.edit' : 'purchases.create') }}</h1><p class="muted">{{ __('purchases.draft_hint') }}</p></div></div>
@if(!$suppliers->contains('is_active', true) || !$products->contains('is_active', true))<div class="notice">{{ __('purchases.need_data') }}</div>@endif
<form method="post" action="{{ $order->exists ? route('purchases.update', $order) : route('purchases.store') }}" data-purchase-form>@csrf @if($order->exists)@method('PATCH')@endif
<input type="hidden" name="key" value="{{ \App\Support\FormValue::old('key', $key) }}">
@if($order->exists)<input type="hidden" name="version" value="{{ \App\Support\FormValue::old('version', $order->version) }}">@endif
<section class="card"><div class="form-grid">
    <label for="supplier_id">{{ __('purchases.supplier') }}<select id="supplier_id" name="supplier_id" required><option value="">{{ __('purchases.select_supplier') }}</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string)\App\Support\FormValue::old('supplier_id', $order->supplier_id) === (string)$supplier->id)>{{ $supplier->name }}{{ $supplier->is_active ? '' : ' · '.__('app.inactive') }}</option>@endforeach</select></label>
    <label for="order_date">{{ __('purchases.order_date') }}<input id="order_date" name="order_date" type="date" required value="{{ \App\Support\FormValue::old('order_date', $order->order_date?->toDateString() ?? $today) }}"></label>
    <label for="notes" class="span-two">{{ __('purchases.notes') }}<textarea id="notes" name="notes" maxlength="2000" rows="3">{{ \App\Support\FormValue::old('notes', $order->notes) }}</textarea></label>
</div></section>
<section class="card"><div class="page-heading"><h2>{{ __('purchases.lines') }}</h2><button class="button small" type="button" data-add-line>+ {{ __('purchases.add_line') }}</button></div>
<div class="table-wrap"><table class="purchase-lines"><thead><tr><th>{{ __('catalog.products') }}</th><th>{{ __('purchases.quantity') }}</th><th>{{ __('purchases.unit') }}</th><th>{{ __('purchases.unit_price') }}</th><th>{{ __('app.actions') }}</th></tr></thead><tbody data-purchase-lines>@foreach($lines as $index => $line)@include('purchases.line', ['index' => $index, 'line' => $line])@endforeach</tbody></table></div>
<p class="muted form-note">{{ __('purchases.draft_hint') }}</p>
<div class="form-actions"><button class="button primary">{{ __('purchases.save_draft') }}</button>@can('purchases.view')<a class="button" href="{{ $order->exists ? route('purchases.show', $order) : route('purchases.index') }}">{{ __('app.cancel') }}</a>@endcan</div>
</section></form>
<template id="purchase-line-template">@include('purchases.line', ['index' => '__INDEX__', 'line' => []])</template>
<script src="{{ asset('js/purchase-lines.js') }}" defer></script>
@endsection
