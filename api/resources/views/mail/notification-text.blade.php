{!! $text !!}
@foreach ($actions as $action)

{{ $action['label'] }}: {!! $action['url'] !!}
@endforeach
@if ($url)

{{ __('notifications.mail.open') }}: {!! $url !!}
@endif

--
{{ __('notifications.mail.footer', ['app' => config('app.name')]) }}
