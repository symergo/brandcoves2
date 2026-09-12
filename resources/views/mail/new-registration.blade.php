{{-- To the owner. English on purpose: one known reader, see NewRegistrationMail. --}}
<x-mail::message>
# Somebody new signed up

@if (trim($name) !== '')
**{{ $name }}** ({{ $email }})
@else
**{{ $email }}**
@endif

Signed in with {{ $method === 'google' ? 'Google' : 'a magic link' }}, in the {{ $market }} market, on {{ now()->format('j F Y \a\t H:i') }} ({{ config('app.timezone') }}).

That makes {{ $total }} {{ $total === 1 ? 'account' : 'accounts' }} on {{ $host }}.

<x-mail::button :url="$adminUrl">
Open the admin panel
</x-mail::button>

</x-mail::message>
