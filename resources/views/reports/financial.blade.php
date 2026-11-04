@extends('layouts.app')
@section('title', __('reports.'.$kind))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('reports.title') }}</span><h1>{{ __('reports.'.$kind) }}</h1><p class="muted">{{ __('reports.'.$kind.'_hint') }}</p></div><a class="button" href="{{ route('reports.index') }}">{{ __('reports.title') }}</a></div>
<section class="card"><form method="get" class="purchase-filters">@include('reports.period')
@php $partyField = $kind === 'sales' ? 'customer_id' : 'supplier_id'; @endphp
<label for="{{ $partyField }}">{{ __($kind === 'sales' ? 'prices.customer' : 'purchases.supplier') }}<select id="{{ $partyField }}" name="{{ $partyField }}"><option value="">{{ __('reports.all_parties') }}</option>@foreach($parties as $party)<option value="{{ $party->id }}" @selected((string)($filters[$partyField] ?? '') === (string)$party->id)>{{ $party->name }}</option>@endforeach</select></label><button class="button">{{ __('reports.apply') }}</button>
</form><p class="muted form-note">{{ __('reports.period_hint', ['timezone' => config('inventory.timezone')]) }}</p></section>
@include('reports.summary')
<section class="card">@include('reports.documents', ['paginated' => true])</section>
@endsection
