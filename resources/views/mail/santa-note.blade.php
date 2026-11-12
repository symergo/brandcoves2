@php
    // Localised to the group's market, not the app default.
    app()->setLocale($language);
@endphp

<x-mail::message>
# {{ $groupTitle }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
<x-mail::button :url="$url">
{{ $cta }}
</x-mail::button>

<small>{{ __('site.auth.mail_fallback') }}<br>{{ $url }}</small>

@if ($muteUrl)
<small>[{{ __('site.santa.mail_mute') }}]({{ $muteUrl }})</small>
@endif
</x-mail::message>
