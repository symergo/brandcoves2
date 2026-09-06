@php
    // Localised to the market the product is in, not the app default.
    app()->setLocale($language);
@endphp

<x-mail::message>
# {{ $heading }}

{{ $body }}

<x-mail::button :url="$url">
{{ __('site.alerts.mail_button') }}
</x-mail::button>

{{--
    Our own product page, never a shop's link, and no product data beyond the
    title and the price. See App\Mail\AlertMail for why that is a compliance
    boundary rather than a stylistic one.
--}}

<small>{{ __('site.alerts.mail_why') }}</small>
</x-mail::message>
