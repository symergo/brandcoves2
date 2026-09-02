@php
    // Localised to the market the reminder is about, not the app default.
    app()->setLocale($language);
@endphp

<x-mail::message>
# {{ $heading }}

{{ $body }}

<x-mail::button :url="$url">
{{ __('site.reminders.mail_button') }}
</x-mail::button>

{{--
    No products, no prices, no claim state — a date, a lead time and a link.
    This lands in an inbox that may be read on a shared screen or forwarded, and
    on a wish list the person it is addressed to is the one who must not learn
    what has been bought. `mail/list-invitation` refuses product data for the
    same reason.
--}}

<small>{{ __('site.reminders.mail_why') }}</small>
</x-mail::message>
