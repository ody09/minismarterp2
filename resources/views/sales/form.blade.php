@extends('layouts.app')
@section('title', __($order->exists ? 'sales.edit' : 'sales.create'))
@section('content')
@php
    $lines = old('items', $order->exists ? $order->items->map->only(['product_id', 'quantity', 'unit'])->all() : [[]]);
    $lines = is_array($lines) ? array_slice(array_values($lines), 0, 100) : [[]];
    $lines = array_map(fn($line) => is_array($line) ? $line : [], $lines ?: [[]]);
@endphp
<div class="page-heading"><div><span class="eyebrow">{{ __('sales.title') }}{{ $order->exists ? ' · '.$order->number() : '' }}</span><h1>{{ __($order->exists ? 'sales.edit' : 'sales.create') }}</h1><p class="muted">{{ __('sales.draft_hint') }}</p></div></div>
<form method="post" action="{{ $order->exists ? route('sales.update', $order) : route('sales.store') }}" data-purchase-form>@csrf @if($order->exists)@method('PATCH')<input type="hidden" name="version" value="{{ \App\Support\FormValue::old('version', $order->version) }}">@endif
<input type="hidden" name="key" value="{{ \App\Support\FormValue::old('key', $key) }}">
<section class="card"><div class="form-grid">
<label for="customer_id">{{ __('prices.customer') }}<select id="customer_id" name="customer_id" required><option value="">{{ __('prices.select_customer') }}</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)\App\Support\FormValue::old('customer_id', $order->customer_id) === (string)$customer->id)>{{ $customer->name }}{{ $customer->is_active ? '' : ' · '.__('app.inactive') }}</option>@endforeach</select></label>
<label for="order_date">{{ __('purchases.order_date') }}<input type="date" id="order_date" name="order_date" max="{{ $today }}" required value="{{ \App\Support\FormValue::old('order_date', $order->order_date?->toDateString() ?? $today) }}"></label>
<label for="notes" class="span-two">{{ __('purchases.notes') }}<textarea id="notes" name="notes" rows="3" maxlength="2000">{{ \App\Support\FormValue::old('notes', $order->notes) }}</textarea></label>
</div></section>
<section class="card"><div class="page-heading"><h2>{{ __('purchases.lines') }}</h2><button type="button" class="button small" data-add-line>+ {{ __('purchases.add_line') }}</button></div>
<div class="table-wrap"><table class="purchase-lines"><thead><tr><th>{{ __('catalog.product') }}</th><th>{{ __('purchases.quantity') }}</th><th>{{ __('purchases.unit') }}</th><th>{{ __('app.actions') }}</th></tr></thead><tbody data-purchase-lines>@foreach($lines as $index => $line)@include('sales.line', ['index' => $index, 'line' => $line])@endforeach</tbody></table></div>
<p class="muted form-note">{{ __('sales.pricing_hint') }}</p><div class="form-actions"><button class="button primary">{{ __('sales.save_draft') }}</button>@can('sales.view')<a class="button" href="{{ $order->exists ? route('sales.show', $order) : route('sales.index') }}">{{ __('app.cancel') }}</a>@endcan</div>
</section></form><template id="purchase-line-template">@include('sales.line', ['index' => '__INDEX__', 'line' => []])</template>
<script src="{{ asset('js/purchase-lines.js') }}" defer></script>
@endsection
