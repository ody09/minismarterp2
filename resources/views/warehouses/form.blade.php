@extends('layouts.app')
@section('title', __('catalog.warehouses'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('catalog.master_data') }}</span><h1>{{ __('catalog.warehouses') }}</h1><p class="muted">{{ __('catalog.warehouse_hint') }}</p></div>@if(! $editable)@can('warehouses.manage')<a class="button primary" href="{{ route('warehouses.edit') }}">{{ __('app.edit') }}</a>@endcan @endif</div>
<section class="card"><form method="post" action="{{ route('warehouses.update') }}">@csrf @method('PATCH')
<div class="form-grid"><label for="name">{{ __('app.name') }}<input id="name" name="name" required maxlength="180" value="{{ \App\Support\FormValue::old('name', $warehouse->name) }}" @readonly(! $editable)></label>
<label for="location">{{ __('catalog.location') }}<input id="location" name="location" maxlength="255" value="{{ \App\Support\FormValue::old('location', $warehouse->location) }}" @readonly(! $editable)></label>
<label for="description" class="span-two">{{ __('catalog.description') }}<textarea id="description" name="description" maxlength="1000" rows="4" @readonly(! $editable)>{{ \App\Support\FormValue::old('description', $warehouse->description) }}</textarea></label></div>
@if($editable)<div class="form-actions"><button class="button primary">{{ __('app.save') }}</button><a class="button" href="{{ auth()->user()->can('warehouses.view') ? route('warehouses.index') : route(auth()->user()->landingRouteName()) }}">{{ __('app.cancel') }}</a></div>@endif
</form></section>
@endsection
