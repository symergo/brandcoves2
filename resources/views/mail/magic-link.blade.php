@php
    // Localised to the market the person was browsing, not the app default.
    app()->setLocale($language);
@endphp

<x-mail::message>
# {{ __('site.auth.mail_heading') }}

{{ __('site.auth.mail_body') }}

<x-mail::button :url="$url">
{{ __('site.auth.mail_button') }}
</x-mail::button>

{{ __('site.auth.mail_expiry') }}

@if ($requestedFrom)
{{-- Lets the recipient recognise a request they did not make. The address is
     the only thing we know about the requester, and it is worth showing. --}}
<small>{{ __('site.auth.mail_requested_from', ['ip' => $requestedFrom]) }}</small>
@endif

{{ __('site.auth.mail_ignore') }}

<small>{{ __('site.auth.mail_fallback') }}<br>{{ $url }}</small>
</x-mail::message>
