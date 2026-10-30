@extends('layouts.app')
@section('title', __($user->exists ? 'app.edit_user' : 'app.create_user'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('app.administration') }}</span><h1>{{ __($user->exists ? 'app.edit_user' : 'app.create_user') }}</h1></div></div>
<section class="card"><form action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" method="post">@csrf @if($user->exists)@method('PATCH')@endif
    <div class="form-grid">
        <label for="name">{{ __('app.name') }}<input id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="255" autocomplete="name"></label>
        <label for="email">{{ __('app.email') }}<input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required maxlength="255" dir="ltr" autocomplete="email"></label>
        <label for="role_id">{{ __('app.role') }}<select id="role_id" name="role_id" required>@foreach($roles as $role)<option value="{{ $role->id }}" @selected((string) old('role_id', $user->role_id) === (string) $role->id)>{{ $role->name }}</option>@endforeach</select></label>
        <div><input type="hidden" name="is_active" value="0"><label class="check-row" for="is_active"><input id="is_active" type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->exists ? $user->is_active : true))>{{ __('app.active') }}</label></div>
        <label for="password">{{ __('app.password') }}<input id="password" type="password" name="password" autocomplete="new-password" minlength="12" maxlength="255" @required(! $user->exists)><small>{{ __('app.password_hint') }} @if($user->exists){{ __('app.optional_password') }}@endif</small></label>
        <label for="password_confirmation">{{ __('app.password_confirmation') }}<input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" maxlength="255" @required(! $user->exists)></label>
    </div>
    <div class="form-actions"><button class="button primary">{{ __('app.save') }}</button><a class="button" href="{{ auth()->user()->can('users.view') ? route('users.index') : route(auth()->user()->landingRouteName()) }}">{{ __('app.cancel') }}</a></div>
</form></section>
@endsection
