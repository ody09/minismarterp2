@extends('layouts.app')
@section('title', __($product->exists ? 'catalog.edit_products' : 'catalog.add_products'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('catalog.master_data') }}</span><h1>{{ __($product->exists ? 'catalog.edit_products' : 'catalog.add_products') }}</h1></div></div>
@if($categories->isEmpty())<div class="notice">{{ __('catalog.need_category') }} @can('categories.manage')<a href="{{ route('categories.create') }}">{{ __('catalog.add_categories') }}</a>@endcan</div>@endif
<section class="card"><form method="post" action="{{ $product->exists ? route('products.update', $product) : route('products.store') }}">@csrf @if($product->exists)@method('PATCH')@endif
<div class="form-grid">
<label for="name">{{ __('app.name') }}<input id="name" name="name" value="{{ \App\Support\FormValue::old('name', $product->name) }}" required maxlength="180"></label>
<label for="category_id">{{ __('catalog.category') }}<select id="category_id" name="category_id" required><option value="">{{ __('catalog.select_category') }}</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) \App\Support\FormValue::old('category_id', $product->category_id) === (string) $category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' · '.__('app.inactive') }}</option>@endforeach</select></label>
<label for="pieces_per_carton">{{ __('catalog.pieces_per_carton') }}<input id="pieces_per_carton" name="pieces_per_carton" type="number" min="1" max="1000000" step="1" required value="{{ $product->stock_started_at ? $product->pieces_per_carton : \App\Support\FormValue::old('pieces_per_carton', $product->pieces_per_carton) }}" @readonly($product->stock_started_at !== null)><small>{{ __('catalog.base_unit') }}: {{ __('catalog.unit_piece') }}</small></label>
<label for="reorder_level">{{ __('catalog.reorder_level') }}<input id="reorder_level" name="reorder_level" type="number" min="0" max="2147483647" step="1" required value="{{ \App\Support\FormValue::old('reorder_level', $product->reorder_level ?? 0) }}"></label>
@foreach(['purchase_unit' => 'carton', 'selling_unit' => 'piece'] as $field => $default)<label for="{{ $field }}">{{ __('catalog.'.$field) }}<select id="{{ $field }}" name="{{ $field }}" required>@foreach(['piece', 'carton'] as $unit)<option value="{{ $unit }}" @selected(\App\Support\FormValue::old($field, $product->$field ?? $default) === $unit)>{{ __('catalog.unit_'.$unit) }}</option>@endforeach</select></label>@endforeach
@foreach(['purchase_price', 'selling_price'] as $field)<label for="{{ $field }}">{{ __('catalog.'.$field) }}<input id="{{ $field }}" name="{{ $field }}" type="number" min="0" max="999999999.999999" step="0.000001" required value="{{ \App\Support\FormValue::old($field, $product->$field ?? '0') }}" dir="ltr"><small>{{ __('catalog.price_hint') }}</small></label>@endforeach
<label for="description" class="span-two">{{ __('catalog.description') }}<textarea id="description" name="description" maxlength="1000" rows="3">{{ \App\Support\FormValue::old('description', $product->description) }}</textarea></label>
@if($product->stock_started_at)<p class="muted span-two">{{ __('inventory.packing_locked') }}</p>@endif
@include('partials.active-field', ['record' => $product])
</div><div class="form-actions"><button class="button primary" @disabled($categories->isEmpty())>{{ __('app.save') }}</button><a class="button" href="{{ auth()->user()->can('products.view') ? route('products.index') : route(auth()->user()->landingRouteName()) }}">{{ __('app.cancel') }}</a></div>
</form></section>
@endsection
