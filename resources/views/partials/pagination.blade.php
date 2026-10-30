@if($paginator->hasPages())
<nav class="pagination" aria-label="{{ __('app.pages') }}">
    @if($paginator->previousPageUrl())<a class="button small" href="{{ $paginator->previousPageUrl() }}">{{ __('app.previous') }}</a>@endif
    <span>{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
    @if($paginator->nextPageUrl())<a class="button small" href="{{ $paginator->nextPageUrl() }}">{{ __('app.next') }}</a>@endif
</nav>
@endif
