@extends('layouts.app')
@section('title', __('sales.title'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">Smart Mini ERP</span><h1>{{ __('sales.title') }}</h1><p class="muted">{{ __('sales.hint') }}</p></div>@can('sales.manage')<a class="button primary" href="{{ route('sales.create') }}">+ {{ __('sales.create') }}</a>@endcan</div>
<section class="card"><form method="get" class="purchase-filters">
<label for="q">{{ __('app.search') }}<input id="q" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('sales.search') }}"></label>
<label for="customer_id">{{ __('prices.customer') }}<select id="customer_id" name="customer_id"><option value="">{{ __('prices.all_customers') }}</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)($filters['customer_id'] ?? '') === (string)$customer->id)>{{ $customer->name }}</option>@endforeach</select></label>
<label for="status">{{ __('purchases.status') }}<select id="status" name="status"><option value="">{{ __('purchases.all_statuses') }}</option>@foreach(['draft', 'confirmed', 'cancelled'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('sales.'.$status) }}</option>@endforeach</select></label>
@foreach(['from', 'to'] as $field)<label for="{{ $field }}">{{ __('purchases.'.$field) }}<input type="date" id="{{ $field }}" name="{{ $field }}" value="{{ $filters[$field] ?? '' }}"></label>@endforeach<button class="button">{{ __('app.search') }}</button>
</form><div class="table-wrap"><table><thead><tr><th>{{ __('sales.number') }}</th><th>{{ __('prices.customer') }}</th><th>{{ __('purchases.order_date') }}</th><th>{{ __('purchases.status') }}</th><th>{{ __('purchases.total') }}</th><th>{{ __('purchases.created_by') }}</th></tr></thead><tbody>
@forelse($orders as $order)<tr><td><a href="{{ route('sales.show', $order) }}" dir="ltr"><strong>{{ $order->number() }}</strong></a></td><td>{{ $order->customer_name }}</td><td dir="ltr">{{ $order->order_date->toDateString() }}</td><td><span class="pill {{ $order->status === 'cancelled' ? 'inactive' : '' }}">{{ __('sales.'.$order->status) }}</span></td><td dir="ltr">{{ $order->total_amount }}</td><td>{{ $order->user->name }}</td></tr>@empty<tr><td colspan="6" class="empty-state">{{ __('sales.empty') }}</td></tr>@endforelse
</tbody></table></div>@include('partials.pagination', ['paginator' => $orders])</section>
@endsection
