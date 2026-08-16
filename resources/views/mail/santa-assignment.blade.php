@php
    // Localised to the group's market, not the app default.
    app()->setLocale($language);
@endphp

<x-mail::message>
# {{ $groupTitle }}

@if ($changed ?? false)
{{-- Says up front that this replaces the earlier mail. Without it the second
     assignment reads as a duplicate of the first and gets ignored, which is
     the exact failure a partial repair exists to avoid. --}}
{{ __('site.santa.email_changed_intro') }}

@endif
{{ __('site.santa.email_intro', ['name' => $gifteeName]) }}

@if ($budget)
{{ __('site.santa.email_budget', ['budget' => $budget]) }}
@endif

@if ($exchangeDate)
{{ __('site.santa.email_date', ['date' => $exchangeDate]) }}
@endif

@if ($gifteeHasList)
{{ __('site.santa.email_list') }}
@else
{{ __('site.santa.email_no_list') }}
@endif

<x-mail::button :url="$meUrl">
{{ __('site.santa.their_list', ['name' => $gifteeName]) }}
</x-mail::button>

{{--
    No product names, images or prices anywhere in this mail — only a link.
    Amazon's licence restricts product advertising content wherever it is
    displayed, not merely where it links to, so a digest with nothing to filter
    cannot be got wrong by a later edit. See docs/features/amazon-compliance.md.
--}}

<small>{{ __('site.auth.mail_fallback') }}<br>{{ $meUrl }}</small>
</x-mail::message>
