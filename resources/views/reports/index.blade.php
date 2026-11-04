@extends('layouts.app')
@section('title', __('reports.title'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">Smart Mini ERP</span><h1>{{ __('reports.title') }}</h1><p class="muted">{{ __('reports.hint') }}</p></div></div>
<div class="stats-grid">@foreach(['sales', 'purchases', 'inventory'] as $kind)@can('reports.'.$kind)<section class="card"><h2>{{ __('reports.'.$kind) }}</h2><p class="muted">{{ __('reports.'.$kind.'_hint') }}</p><a class="button" href="{{ route('reports.'.$kind) }}">{{ __('reports.open') }}</a></section>@endcan @endforeach</div>
@endsection
