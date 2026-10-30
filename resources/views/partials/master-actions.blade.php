@can($resource.'.manage')
<div class="actions">
    <a href="{{ route($resource.'.edit', $record) }}">{{ __('app.edit') }}</a>
    <form method="post" action="{{ route($resource.'.destroy', $record) }}" data-confirm="{{ __('catalog.delete_confirm') }}">@csrf @method('DELETE')<button class="button small danger">{{ __('app.delete') }}</button></form>
</div>
@endcan
