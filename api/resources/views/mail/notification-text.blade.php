{!! $text !!}
@if ($url)

{{ __('notifications.mail.open') }}: {!! $url !!}
@endif

--
{{ __('notifications.mail.footer', ['app' => config('app.name')]) }}
