@extends('layouts.app')
@section('title', __('app.roles'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('app.administration') }}</span><h1>{{ __('app.roles') }}</h1><p class="muted">{{ __('app.roles_hint') }}</p></div>@can('roles.manage')<a class="button primary" href="{{ route('roles.create') }}">+ {{ __('app.create_role') }}</a>@endcan</div>
<section class="card"><div class="table-wrap"><table><thead><tr><th>{{ __('app.name') }}</th><th>{{ __('app.permissions') }}</th><th>{{ __('app.users') }}</th><th>{{ __('app.actions') }}</th></tr></thead>
<tbody>@forelse($roles as $role)<tr><td><strong>{{ $role->name }}</strong><div class="muted" dir="ltr">{{ $role->code }}</div></td><td><div class="permission-list">@forelse($role->permissions as $permission)<span class="pill">{{ __('app.permission_labels.'.$permission->code) }}</span>@empty<span class="muted">{{ __('app.no_permissions') }}</span>@endforelse</div></td><td>{{ $role->users_count }}</td><td>
    @if($role->is_system)<span class="muted">{{ __('app.system_role') }}</span>@else @can('roles.manage')<div class="actions"><a href="{{ route('roles.edit', $role) }}">{{ __('app.edit') }}</a>@if($role->users_count === 0)<form method="post" action="{{ route('roles.destroy', $role) }}">@csrf @method('DELETE')<button class="button small danger">{{ __('app.delete') }}</button></form>@endif</div>@endcan @endif
</td></tr>@empty<tr><td colspan="4">{{ __('app.no_results') }}</td></tr>@endforelse</tbody></table></div>@include('partials.pagination', ['paginator' => $roles])</section>
@endsection
