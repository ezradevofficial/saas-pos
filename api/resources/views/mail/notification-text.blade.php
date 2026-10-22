{!! $text !!}
@foreach ($actions as $action)

{{ $action['label'] }}: {!! $action['url'] !!}
@endforeach
@if ($url)

{{ __('notifications.mail.open') }}: {!! $url !!}
@endif

--
@if ($unsubscribe ?? null)
{{ __('notifications.marketing.footer') }}
{{ __('notifications.marketing.unsubscribe') }}: {!! $unsubscribe !!}
@else
{{ __('notifications.mail.footer', ['app' => config('app.name')]) }}
@endif
