@php
    // In the inviting member's market language: a queued mail has no request,
    // and we know nothing about the reader's own language.
    app()->setLocale($language);
@endphp

<x-mail::message>
# {{ __('site.invite_mail.subject', ['name' => $inviterName]) }}

{{ __('site.invite_mail.what', ['name' => $inviterName]) }}

<x-mail::button :url="$url">
{{ __('site.invite_mail.button') }}
</x-mail::button>

{{ __('site.invite_mail.nothing_to_do') }}

{{--
    The same email for an address with an account and one without, on purpose:
    see App\Services\Social\InviteMailer. Nothing here may depend on which.
--}}

<small>{{ __('site.invite_mail.why', ['name' => $inviterName]) }} <a href="{{ $notWantedUrl }}">{{ __('site.invite_mail.not_wanted') }}</a></small>
</x-mail::message>
