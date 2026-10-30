@extends('layouts.app')
@section('title', __('app.users'))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('app.administration') }}</span><h1>{{ __('app.users') }}</h1><p class="muted">{{ __('app.users_hint') }}</p></div>@can('users.manage')<a class="button primary" href="{{ route('users.create') }}">+ {{ __('app.create_user') }}</a>@endcan</div>
<section class="card">
    <form method="get" class="search-bar"><label class="search-field" for="q"><span class="sr-only">{{ __('app.search_users') }}</span><input id="q" name="q" type="search" value="{{ $search }}" placeholder="{{ __('app.search_users') }}" maxlength="100"></label><button class="button">{{ __('app.search') }}</button></form>
    <div class="table-wrap"><table><thead><tr><th>{{ __('app.name') }}</th><th>{{ __('app.email') }}</th><th>{{ __('app.role') }}</th><th>{{ __('app.account_status') }}</th><th>{{ __('app.actions') }}</th></tr></thead>
        <tbody>@forelse($users as $user)<tr><td><strong>{{ $user->name }}</strong></td><td dir="ltr">{{ $user->email }}</td><td>{{ $user->role->name }}</td><td><span class="pill {{ $user->is_active ? '' : 'inactive' }}">{{ __($user->is_active ? 'app.active' : 'app.inactive') }}</span></td><td>@can('users.manage')<a href="{{ route('users.edit', $user) }}">{{ __('app.edit') }}</a>@endcan</td></tr>@empty<tr><td colspan="5">{{ __('app.no_results') }}</td></tr>@endforelse</tbody>
    </table></div>
    @include('partials.pagination', ['paginator' => $users])
</section>
@endsection
