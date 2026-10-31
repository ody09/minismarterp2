@extends('layouts.app')
@section('title', $order->number())
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('purchases.title') }}</span><h1 dir="ltr">{{ $order->number() }}</h1><span class="pill {{ $order->status === 'cancelled' ? 'inactive' : '' }}">{{ __('purchases.'.$order->status) }}</span></div><div class="actions">@if($order->status === 'draft')@can('purchases.manage')<a class="button" href="{{ route('purchases.edit', $order) }}">{{ __('purchases.edit') }}</a>@endcan @can('purchases.receive')@can('inventory.post')<a class="button primary" href="{{ route('purchases.receipt', $order) }}">{{ __('purchases.receive') }}</a>@endcan @endcan @endif</div></div>
<section class="card"><dl class="purchase-details">
    <div><dt>{{ __('purchases.supplier') }}</dt><dd>{{ $order->supplier_name }}</dd></div>
    <div><dt>{{ __('purchases.order_date') }}</dt><dd dir="ltr">{{ $order->order_date->toDateString() }}</dd></div>
    <div><dt>{{ __('purchases.created_by') }}</dt><dd>{{ $order->user->name }}</dd></div>
    @if($order->received_at)<div><dt>{{ __('purchases.received_by') }}</dt><dd>{{ $order->receiver->name }}</dd></div><div><dt>{{ __('purchases.received_at') }}</dt><dd dir="ltr">{{ $order->received_at->setTimezone(config('inventory.timezone'))->format('Y-m-d H:i') }}</dd></div>@endif
    @if($order->cancelled_at)<div><dt>{{ __('purchases.cancelled_by') }}</dt><dd>{{ $order->canceller->name }}</dd></div><div><dt>{{ __('purchases.cancelled_at') }}</dt><dd dir="ltr">{{ $order->cancelled_at->setTimezone(config('inventory.timezone'))->format('Y-m-d H:i') }}</dd></div>@endif
</dl>@if($order->notes)<p class="purchase-notes">{{ $order->notes }}</p>@endif</section>
<section class="card"><h2>{{ __('purchases.lines') }}</h2><div class="table-wrap"><table><thead><tr><th>#</th><th>{{ __('catalog.products') }}</th><th>{{ __('purchases.quantity') }}</th><th>{{ __('purchases.unit') }}</th><th>{{ __('purchases.unit_price') }}</th><th>{{ __('purchases.subtotal') }}</th><th>{{ __('catalog.batch_number') }}</th></tr></thead><tbody>
@foreach($order->items as $item)<tr><td>{{ $item->line_number }}</td><td>{{ $item->product_name }}<small class="cell-note muted">{{ __('purchases.factor') }}: {{ $item->conversion_factor }}</small></td><td>{{ $item->quantity }}</td><td>{{ __('catalog.unit_'.$item->unit) }}</td><td dir="ltr">{{ $item->unit_price }}</td><td dir="ltr">{{ $item->subtotal }}</td><td>{{ $item->batch?->batch_number ?? '—' }}</td></tr>@endforeach
</tbody><tfoot><tr><th colspan="5">{{ __('purchases.total') }}</th><td colspan="2" dir="ltr"><strong>{{ $order->total_amount }}</strong></td></tr></tfoot></table></div>
<div class="form-actions">@can('purchases.view')<a class="button" href="{{ route('purchases.index') }}">{{ __('purchases.back') }}</a>@endcan @if($order->stock_operation_id)@can('inventory.view')<a class="button" href="{{ route('inventory.operation', $order->stock_operation_id) }}">{{ __('purchases.stock_link') }}</a>@endcan @endif</div>
</section>
@if($order->status === 'draft')@can('purchases.manage')<form method="post" action="{{ route('purchases.cancel', $order) }}" data-confirm="{{ __('purchases.confirm_cancel') }}">@csrf<input type="hidden" name="version" value="{{ $order->version }}"><button class="button danger">{{ __('purchases.cancel_order') }}</button></form>@endcan
@else<p class="muted">{{ __('purchases.terminal_hint') }}</p>@endif
@endsection
