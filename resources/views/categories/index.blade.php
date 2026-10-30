@extends('layouts.app')
@section('title', __('catalog.categories'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('catalog.master_data') }}</span><h1>{{ __('catalog.categories') }}</h1><p class="muted">{{ __('catalog.categories_hint') }}</p></div>@can('categories.manage')<a class="button primary" href="{{ route('categories.create') }}">+ {{ __('catalog.add_categories') }}</a>@endcan</div>
<section class="card"><form class="search-bar" method="get"><label class="search-field" for="q"><span class="sr-only">{{ __('catalog.search_name') }}</span><input id="q" name="q" type="search" value="{{ $search }}" placeholder="{{ __('catalog.search_name') }}" maxlength="100"></label><button class="button">{{ __('app.search') }}</button></form>
<div class="table-wrap"><table><thead><tr><th>{{ __('app.name') }}</th><th>{{ __('catalog.products') }}</th><th>{{ __('catalog.status') }}</th><th>{{ __('app.actions') }}</th></tr></thead><tbody>
@forelse($categories as $category)<tr><td><strong>{{ $category->name }}</strong>@if($category->description)<div class="muted cell-note">{{ $category->description }}</div>@endif</td><td>{{ $category->products_count }}</td><td>@include('partials.record-status', ['record' => $category])</td><td>@include('partials.master-actions', ['record' => $category, 'resource' => 'categories'])</td></tr>
@empty<tr><td colspan="4" class="empty-state">{{ __('catalog.empty_categories') }}</td></tr>@endforelse
</tbody></table></div>@include('partials.pagination', ['paginator' => $categories])</section>
@endsection
