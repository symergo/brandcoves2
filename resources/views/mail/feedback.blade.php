{{-- To the owner. English on purpose: one known reader, see FeedbackMail. --}}
<x-mail::message>
# New feedback from the help page

<x-mail::panel>
{!! nl2br(e($body)) !!}
</x-mail::panel>

@if ($email)
From **{{ $email }}**{{ $signedIn ? ' (signed in)' : '' }}. Reply to this email to answer them.
@else
No address left{{ $signedIn ? ', but they were signed in' : '' }}, so there is nobody to reply to.
@endif

Sent in the {{ $market }} market, on {{ now()->format('j F Y \a\t H:i') }} ({{ config('app.timezone') }}).
@if ($pageUrl)
About the page: {{ $pageUrl }}
@endif

<x-mail::button :url="$adminUrl">
Open the feedback queue
</x-mail::button>

</x-mail::message>
