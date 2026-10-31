@extends('layouts.app')
@section('title', __('inventory.operation'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('inventory.operation') }}</span><h1>{{ $operation->reference }}</h1><p class="muted">{{ __('inventory.'.$operation->type) }} · {{ $operation->user->name }} · <span dir="ltr">{{ $operation->posted_at->setTimezone(config('inventory.timezone'))->format('Y-m-d H:i') }}</span></p></div><a class="button" href="{{ route('inventory.transactions') }}">{{ __('inventory.transactions') }}</a></div>
@if($operation->reason)<div class="notice">{{ __('inventory.reason') }}: {{ $operation->reason }}</div>@endif
@can('purchases.view')@if($operation->purchaseOrder)<p>{{ __('purchases.number') }}: <a href="{{ route('purchases.show', $operation->purchaseOrder) }}" dir="ltr">{{ $operation->purchaseOrder->number() }}</a></p>@endif @endcan
@if($operation->original)<p>{{ __('inventory.original') }}: <a href="{{ route('inventory.operation', $operation->original) }}">{{ $operation->original->reference }}</a></p>@endif
@if($operation->reversal)<p>{{ __('inventory.reversed_by') }}: <a href="{{ route('inventory.operation', $operation->reversal) }}">{{ $operation->reversal->reference }}</a></p>@endif
<section class="card"><div class="table-wrap"><table><thead><tr><th>{{ __('inventory.line') }}</th><th>{{ __('inventory.allocation') }}</th><th>{{ __('catalog.product') }}</th><th>{{ __('catalog.batch_number') }}</th><th>{{ __('inventory.unit_snapshot') }}</th><th>{{ __('inventory.quantity') }}</th></tr></thead><tbody>
@foreach($transactions as $move)<tr><td>{{ $move->line_number }}</td><td>{{ $move->allocation_number }}</td><td>{{ $move->inventory->batch->product->name }}</td><td>{{ $move->inventory->batch->batch_number }}</td><td>{{ $move->input_quantity }} {{ __('catalog.unit_'.$move->unit) }}<span class="muted cell-note">× {{ $move->conversion_factor }}</span></td><td dir="ltr">{{ $move->quantity > 0 ? '+' : '' }}{{ $move->quantity }}</td></tr>@endforeach
</tbody></table></div>@include('partials.pagination', ['paginator' => $transactions])</section>
@endsection
