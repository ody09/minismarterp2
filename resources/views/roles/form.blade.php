@extends('layouts.app')
@section('title', __($role->exists ? 'app.edit_role' : 'app.create_role'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('app.administration') }}</span><h1>{{ __($role->exists ? 'app.edit_role' : 'app.create_role') }}</h1></div></div>
<section class="card"><form method="post" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}">@csrf @if($role->exists)@method('PATCH')@endif
    <div class="form-grid"><label for="name">{{ __('app.name') }}<input id="name" name="name" required maxlength="100" value="{{ old('name', $role->name) }}"></label><label for="code">{{ __('app.code') }}<input id="code" name="code" required maxlength="60" pattern="[a-z][a-z0-9_-]*" value="{{ old('code', $role->code) }}" dir="ltr"></label></div>
    <fieldset><legend>{{ __('app.permissions') }}</legend><div class="checks">@foreach($permissions as $permission)<label class="check-row" for="permission_{{ $permission->id }}"><input id="permission_{{ $permission->id }}" type="checkbox" name="permissions[]" value="{{ $permission->code }}" @checked(in_array($permission->code, old('permissions', $role->exists ? $role->permissions->pluck('code')->all() : ['dashboard.view']), true))>{{ __('app.permission_labels.'.$permission->code) }}</label>@endforeach</div></fieldset>
    <div class="form-actions"><button class="button primary">{{ __('app.save') }}</button><a class="button" href="{{ auth()->user()->can('roles.view') ? route('roles.index') : route(auth()->user()->landingRouteName()) }}">{{ __('app.cancel') }}</a></div>
</form></section>
@endsection
