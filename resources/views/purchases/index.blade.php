@extends('layouts.app')
@section('title', __('purchases.title'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">Smart Mini ERP</span><h1>{{ __('purchases.title') }}</h1><p class="muted">{{ __('purchases.hint') }}</p></div>@can('purchases.manage')<a class="button primary" href="{{ route('purchases.create') }}">+ {{ __('purchases.create') }}</a>@endcan</div>
<section class="card">
<form class="purchase-filters" method="get">
    <label for="q">{{ __('app.search') }}<input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('purchases.search') }}" maxlength="100"></label>
    <label for="supplier_id">{{ __('purchases.supplier') }}<select id="supplier_id" name="supplier_id"><option value="">{{ __('purchases.all_suppliers') }}</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string)($filters['supplier_id'] ?? '') === (string)$supplier->id)>{{ $supplier->name }}</option>@endforeach</select></label>
    <label for="status">{{ __('purchases.status') }}<select id="status" name="status"><option value="">{{ __('purchases.all_statuses') }}</option>@foreach(['draft', 'received', 'cancelled'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('purchases.'.$status) }}</option>@endforeach</select></label>
    <label for="from">{{ __('purchases.from') }}<input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}"></label>
    <label for="to">{{ __('purchases.to') }}<input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}"></label>
    <button class="button">{{ __('app.search') }}</button>
</form>
<div class="table-wrap"><table><thead><tr><th>{{ __('purchases.number') }}</th><th>{{ __('purchases.supplier') }}</th><th>{{ __('purchases.order_date') }}</th><th>{{ __('purchases.status') }}</th><th>{{ __('purchases.total') }}</th><th>{{ __('purchases.created_by') }}</th></tr></thead><tbody>
@forelse($orders as $order)<tr><td><a href="{{ route('purchases.show', $order) }}"><strong dir="ltr">{{ $order->number() }}</strong></a></td><td>{{ $order->supplier_name }}</td><td dir="ltr">{{ $order->order_date->toDateString() }}</td><td><span class="pill {{ $order->status === 'cancelled' ? 'inactive' : '' }}">{{ __('purchases.'.$order->status) }}</span></td><td dir="ltr">{{ $order->total_amount }}</td><td>{{ $order->user->name }}</td></tr>
@empty<tr><td colspan="6" class="empty-state">{{ __('purchases.empty') }}</td></tr>@endforelse
</tbody></table></div>@include('partials.pagination', ['paginator' => $orders])
</section>
@endsection
