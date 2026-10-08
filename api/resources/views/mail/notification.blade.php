<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $mailSubject }}</title>
</head>
<body style="margin:0;padding:24px;background:#ffffff;color:#000000;font-family:Geist,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;">
<div style="max-width:560px;margin:0 auto;">
<p style="margin:0 0 16px;">{!! $html !!}</p>
@foreach ($actions as $action)
<p style="margin:0 0 12px;"><a href="{{ $action['url'] }}" style="color:#000000;">{{ $action['label'] }}</a></p>
@endforeach
@if ($url)
<p style="margin:0 0 24px;"><a href="{{ $url }}" style="color:#000000;">{{ __('notifications.mail.open') }}</a></p>
@endif
<p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #000000;font-size:13px;">{{ __('notifications.mail.footer', ['app' => config('app.name')]) }}</p>
</div>
</body>
</html>
