@extends('layouts.app')
@section('title', __($category->exists ? 'catalog.edit_categories' : 'catalog.add_categories'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('catalog.master_data') }}</span><h1>{{ __($category->exists ? 'catalog.edit_categories' : 'catalog.add_categories') }}</h1></div></div>
<section class="card"><form action="{{ $category->exists ? route('categories.update', $category) : route('categories.store') }}" method="post" class="stack">@csrf @if($category->exists)@method('PATCH')@endif
<label for="name">{{ __('app.name') }}<input id="name" name="name" value="{{ \App\Support\FormValue::old('name', $category->name) }}" required maxlength="180"></label>
<label for="description">{{ __('catalog.description') }}<textarea id="description" name="description" maxlength="1000" rows="3">{{ \App\Support\FormValue::old('description', $category->description) }}</textarea></label>
@include('partials.active-field', ['record' => $category])
<div class="form-actions"><button class="button primary">{{ __('app.save') }}</button><a class="button" href="{{ auth()->user()->can('categories.view') ? route('categories.index') : route(auth()->user()->landingRouteName()) }}">{{ __('app.cancel') }}</a></div>
</form></section>
@endsection
