@php
    // Localised to the list's market, not the app default.
    app()->setLocale($language);
@endphp

<x-mail::message>
# {{ __('site.invitations.mail_heading') }}

@if ($forName)
{{ __('site.invitations.mail_intro_for', ['name' => $fromName, 'person' => $forName]) }}
@else
{{ __('site.invitations.mail_intro', ['name' => $fromName, 'list' => $listTitle]) }}
@endif

{{ __('site.invitations.mail_what') }}

<x-mail::button :url="$url">
{{ __('site.invitations.mail_button') }}
</x-mail::button>

{{ __('site.invitations.mail_expiry') }}

{{--
    No products, prices or images — a list title, who is asking, and a link.
    This list is private research about a third person, and its contents must
    not reach an inbox that has not yet proved it belongs to the invitee.
--}}

<small>{{ __('site.auth.mail_fallback') }}<br>{{ $url }}</small>
</x-mail::message>
