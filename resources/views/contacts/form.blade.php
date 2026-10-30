@extends('layouts.app')
@section('title', __('catalog.'.($contact->exists ? 'edit_' : 'add_').$resource))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('catalog.master_data') }}</span><h1>{{ __('catalog.'.($contact->exists ? 'edit_' : 'add_').$resource) }}</h1></div></div>
<section class="card"><form method="post" action="{{ $contact->exists ? route($resource.'.update', $contact) : route($resource.'.store') }}">@csrf @if($contact->exists)@method('PATCH')@endif
<div class="form-grid"><label for="name">{{ __('app.name') }}<input id="name" name="name" required maxlength="180" value="{{ \App\Support\FormValue::old('name', $contact->name) }}" autocomplete="organization"></label>
<label for="phone">{{ __('catalog.phone') }}<input id="phone" name="phone" type="tel" maxlength="40" value="{{ \App\Support\FormValue::old('phone', $contact->phone) }}" dir="ltr" autocomplete="tel"></label>
<label for="email">{{ __('app.email') }}<input id="email" name="email" type="email" maxlength="255" value="{{ \App\Support\FormValue::old('email', $contact->email) }}" dir="ltr" autocomplete="email"></label>
@include('partials.active-field', ['record' => $contact])
<label for="address" class="span-two">{{ __('catalog.address') }}<textarea id="address" name="address" maxlength="1000" rows="3" autocomplete="street-address">{{ \App\Support\FormValue::old('address', $contact->address) }}</textarea></label>
</div><div class="form-actions"><button class="button primary">{{ __('app.save') }}</button><a class="button" href="{{ auth()->user()->can($resource.'.view') ? route($resource.'.index') : route(auth()->user()->landingRouteName()) }}">{{ __('app.cancel') }}</a></div>
</form></section>
@endsection
