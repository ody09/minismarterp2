@extends('layouts.app')
@section('title', __('catalog.'.$resource))
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ __('catalog.master_data') }}</span><h1>{{ __('catalog.'.$resource) }}</h1><p class="muted">{{ __('catalog.'.$resource.'_hint') }}</p></div>@can($resource.'.manage')<a class="button primary" href="{{ route($resource.'.create') }}">+ {{ __('catalog.add_'.$resource) }}</a>@endcan</div>
<section class="card"><form class="search-bar" method="get"><label class="search-field" for="q"><span class="sr-only">{{ __('catalog.search_contacts') }}</span><input id="q" name="q" type="search" value="{{ $search }}" placeholder="{{ __('catalog.search_contacts') }}" maxlength="100"></label><button class="button">{{ __('app.search') }}</button></form>
<div class="table-wrap"><table><thead><tr><th>{{ __('app.name') }}</th><th>{{ __('catalog.phone') }}</th><th>{{ __('app.email') }}</th><th>{{ __('catalog.address') }}</th><th>{{ __('catalog.status') }}</th><th>{{ __('app.actions') }}</th></tr></thead><tbody>
@forelse($contacts as $contact)<tr><td><strong>{{ $contact->name }}</strong></td><td dir="ltr">{{ $contact->phone ?? '—' }}</td><td dir="ltr">{{ $contact->email ?? '—' }}</td><td><span class="cell-note">{{ $contact->address ?? '—' }}</span></td><td>@include('partials.record-status', ['record' => $contact])</td><td>@include('partials.master-actions', ['record' => $contact, 'resource' => $resource])</td></tr>@empty<tr><td colspan="6" class="empty-state">{{ __('catalog.empty_'.$resource) }}</td></tr>@endforelse
</tbody></table></div>@include('partials.pagination', ['paginator' => $contacts])</section>
@endsection
