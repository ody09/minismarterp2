@extends('layouts.app')
@section('title', __($price->exists ? 'prices.edit' : 'prices.create'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('prices.title') }}</span><h1>{{ __($price->exists ? 'prices.edit' : 'prices.create') }}</h1><p class="muted">{{ __('prices.form_hint') }}</p></div></div>
<section class="card"><form method="post" action="{{ $price->exists ? route('prices.update', $price) : route('prices.store') }}">@csrf @if($price->exists)@method('PATCH')<input type="hidden" name="version" value="{{ \App\Support\FormValue::old('version', $price->version) }}">@endif
<div class="form-grid">
<label for="customer_id">{{ __('prices.customer') }}<select id="customer_id" name="customer_id" required><option value="">{{ __('prices.select_customer') }}</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)\App\Support\FormValue::old('customer_id', $price->customer_id) === (string)$customer->id)>{{ $customer->name }}{{ $customer->is_active ? '' : ' · '.__('app.inactive') }}</option>@endforeach</select></label>
<label for="product_id">{{ __('catalog.product') }}<select id="product_id" name="product_id" required><option value="">{{ __('purchases.select_product') }}</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string)\App\Support\FormValue::old('product_id', $price->product_id) === (string)$product->id)>{{ $product->name }}{{ $product->is_active ? '' : ' · '.__('app.inactive') }}</option>@endforeach</select></label>
<label for="unit">{{ __('purchases.unit') }}<select id="unit" name="unit" required>@foreach(['piece', 'carton'] as $unit)<option value="{{ $unit }}" @selected(\App\Support\FormValue::old('unit', $price->unit ?? 'piece') === $unit)>{{ __('catalog.unit_'.$unit) }}</option>@endforeach</select></label>
<label for="min_quantity">{{ __('prices.min_quantity') }}<input id="min_quantity" name="min_quantity" type="number" min="1" max="2147483647" step="1" required value="{{ \App\Support\FormValue::old('min_quantity', $price->min_quantity ?? 1) }}"></label>
<label for="unit_price" class="span-two">{{ __('purchases.unit_price') }}<input id="unit_price" name="unit_price" type="number" min="0" max="999999999.999999" step="0.000001" dir="ltr" required value="{{ \App\Support\FormValue::old('unit_price', $price->unit_price ?? '0.000000') }}"></label>
@foreach(['valid_from', 'valid_to'] as $field)<label for="{{ $field }}">{{ __('prices.'.$field) }}<input type="date" id="{{ $field }}" name="{{ $field }}" value="{{ \App\Support\FormValue::old($field, $price->$field?->toDateString()) }}"></label>@endforeach
@include('partials.active-field', ['record' => $price])
</div><div class="form-actions"><button class="button primary">{{ __('app.save') }}</button>@can('prices.view')<a class="button" href="{{ route('prices.index') }}">{{ __('app.cancel') }}</a>@endcan</div>
</form></section>
@endsection
