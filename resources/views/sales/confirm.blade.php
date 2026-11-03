@extends('layouts.app')
@section('title', __('sales.confirm'))
@section('content')
<div class="page-heading"><div><span class="eyebrow" dir="ltr">{{ $order->number() }}</span><h1>{{ __('sales.confirm') }}</h1><p class="muted">{{ __('sales.review_hint') }}</p></div></div>
<section class="card"><p><strong>{{ __('prices.customer') }}:</strong> {{ $order->customer_name }} · <span dir="ltr">{{ $order->order_date->toDateString() }}</span></p>@include('sales.items')
<form method="post" action="{{ route('sales.confirm', $order) }}">@csrf
<input type="hidden" name="key" value="{{ \App\Support\FormValue::old('key', $key) }}"><input type="hidden" name="version" value="{{ \App\Support\FormValue::old('version', $order->version) }}">
<div class="form-actions"><button class="button primary">{{ __('sales.confirm_action') }}</button>@can('sales.manage')<a class="button" href="{{ route('sales.edit', $order) }}">{{ __('sales.edit') }}</a>@endcan @can('sales.view')<a class="button" href="{{ route('sales.show', $order) }}">{{ __('app.cancel') }}</a>@endcan</div>
</form></section>
@endsection
