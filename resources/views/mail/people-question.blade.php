@php
    // In the receiver's language, not the app default: a queued mail has no request.
    app()->setLocale($language);
@endphp

<x-mail::message>
# {{ __('site.ask.people.mail_heading', ['name' => $askerName]) }}

> {{ $question }}

{{ __('site.ask.people.mail_body', ['name' => $askerName]) }}

<x-mail::button :url="$url">
{{ __('site.ask.people.mail_button') }}
</x-mail::button>

{{--
    The asker's name and the question's title, both public on the board once
    the question is published. Nothing from any list: the list a question was
    asked from is the asker's own business.
--}}

<small>{{ __('site.ask.people.mail_why', ['name' => $askerName]) }} <a href="{{ $unsubscribeUrl }}">{{ __('site.ask.people.mail_stop') }}</a></small>
</x-mail::message>
