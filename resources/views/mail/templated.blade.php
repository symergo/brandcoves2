@php
    // Localised to the market the mail is about, not the app default.
    app()->setLocale($language);
@endphp

<x-mail::message>
{{--
    An editor's body, rendered as Markdown by the mail component.

    Everything around it is ours and stays ours: the button, its destination and
    the fallback line. Those are the parts that fail silently — a template that
    lost its button is an email nobody can act on, and a URL typed into a body is
    wrong the moment the market changes. See App\Services\Mail\MailTemplates.
--}}
{!! $body !!}

<x-mail::button :url="$url">
{{ $button }}
</x-mail::button>

<small>{{ __('site.auth.mail_fallback') }}<br>{{ $url }}</small>
</x-mail::message>
