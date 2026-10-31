@extends('layouts.app')
@section('title', __('purchases.receive'))
@section('content')
<div class="page-heading"><div><span class="eyebrow" dir="ltr">{{ $order->number() }}</span><h1>{{ __('purchases.receive') }}</h1><p class="muted">{{ __('purchases.receipt_hint') }}</p></div></div>
<form method="post" action="{{ route('purchases.receive', $order) }}" data-confirm="{{ __('purchases.confirm_receive_message') }}">@csrf
<input type="hidden" name="key" value="{{ \App\Support\FormValue::old('key', $key) }}">
<input type="hidden" name="version" value="{{ \App\Support\FormValue::old('version', $order->version) }}">
@foreach($order->items as $index => $item)
<section class="card" data-receipt-line><h2>{{ $item->line_number }}. {{ $item->product_name }}</h2><p class="muted">{{ $item->quantity }} {{ __('catalog.unit_'.$item->unit) }} · {{ $item->quantity * $item->conversion_factor }} {{ __('purchases.pieces') }}</p>
<input type="hidden" name="items[{{ $index }}][item_id]" value="{{ $item->id }}">
<div class="form-grid">
<label for="batch_{{ $index }}" class="span-two">{{ __('purchases.existing_batch') }}<select id="batch_{{ $index }}" name="items[{{ $index }}][batch_id]" data-existing-batch><option value="">{{ __('purchases.new_batch') }}</option>@foreach($batches->get($item->product_id, collect()) as $batch)<option value="{{ $batch->id }}" @selected((string)\App\Support\FormValue::old('items.'.$index.'.batch_id') === (string)$batch->id)>{{ $batch->batch_number }}{{ $batch->expiration_date ? ' · '.__('catalog.expiration_date').': '.$batch->expiration_date->toDateString() : '' }}</option>@endforeach</select></label>
<div class="span-two form-grid" data-new-batch-fields>
<p class="muted span-two">{{ __('purchases.new_batch_hint') }}</p>
<label for="batch_number_{{ $index }}" class="span-two">{{ __('catalog.batch_number') }}<input id="batch_number_{{ $index }}" name="items[{{ $index }}][batch_number]" maxlength="100" value="{{ \App\Support\FormValue::old('items.'.$index.'.batch_number') }}"></label>
<label for="production_date_{{ $index }}">{{ __('catalog.production_date') }}<input type="date" id="production_date_{{ $index }}" name="items[{{ $index }}][production_date]" value="{{ \App\Support\FormValue::old('items.'.$index.'.production_date') }}"></label>
<label for="expiration_date_{{ $index }}">{{ __('catalog.expiration_date') }}<input type="date" id="expiration_date_{{ $index }}" name="items[{{ $index }}][expiration_date]" value="{{ \App\Support\FormValue::old('items.'.$index.'.expiration_date') }}"></label>
</div></div></section>
@endforeach
<div class="form-actions"><button class="button primary">{{ __('purchases.confirm_receive') }}</button>@can('purchases.view')<a class="button" href="{{ route('purchases.show', $order) }}">{{ __('app.cancel') }}</a>@endcan</div>
</form><script src="{{ asset('js/purchase-lines.js') }}" defer></script>
@endsection
