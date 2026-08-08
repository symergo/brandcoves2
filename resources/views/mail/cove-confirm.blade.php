@php
    // Localised to the market they subscribed from, not the app default.
    app()->setLocale($language);
@endphp

<x-mail::message>
# {{ __('site.cove_mail.confirm_heading') }}

{{ __('site.cove_mail.confirm_body') }}

<x-mail::button :url="$url">
{{ __('site.cove_mail.confirm_button') }}
</x-mail::button>

{{ __('site.cove_mail.confirm_expiry') }}

@if ($requestedFrom)
{{-- Lets the recipient recognise a signup they did not make. Anyone can type
     any address into a form, which is the whole reason this email exists. --}}
<small>{{ __('site.cove_mail.confirm_requested_from', ['ip' => $requestedFrom]) }}</small>
@endif

{{ __('site.cove_mail.confirm_ignore') }}

<small>{{ __('site.auth.mail_fallback') }}<br>{{ $url }}</small>
</x-mail::message>
