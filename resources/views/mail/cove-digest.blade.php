@php
    app()->setLocale($language);
@endphp

<x-mail::message>
# {{ $digest['theme'] }}

@if ($digest['blurb'])
{{ $digest['blurb'] }}
@endif

@if ($digest['lead'])
{{ $digest['lead'] }}
@endif

@if (! empty($digest['finds']))
{{--
  Names and prices only, with links to our own barcode search.

  Nothing here comes from Amazon: DigestBuilder has already excluded any product
  we do not hold a name for from a non-Amazon source. That is the PA-API rule,
  which restricts the *content* rather than the destination, so a compliant link
  next to an Amazon title would not launder it.

  The link is /search?q={ean}, not a product page: SearchService treats a GTIN as
  an exact identity and queries the live sources, so the reader lands on the full
  comparison — Amazon included, fetched live, on our page where it is licensed to
  appear. See docs/features/amazon-compliance.md.
--}}
@foreach ($digest['finds'] as $find)
- **[{{ $find['title'] }}]({{ url($find['url']) }})**@if ($find['price'] !== null) — {{ Illuminate\Support\Number::currency($find['price'] / 100, $market->currency(), $market->hrefLang()) }}@endif
@if ($find['shops'] > 1) · {{ __('site.cove_mail.across_shops', ['count' => $find['shops']]) }}@endif

@endforeach
@endif

@if ($digest['omitted'] > 0)
{{ __('site.cove_mail.more_on_page', ['count' => $digest['omitted']]) }}
@endif

<x-mail::button :url="$editionUrl">
{{ __('site.cove_mail.digest_button') }}
</x-mail::button>

---

<small>
{{ __('site.cove_mail.why_receiving') }}
[{{ __('site.cove_mail.unsubscribe') }}]({{ $unsubscribeUrl }})
</small>

<small>{{ __('site.footer.affiliate') }}</small>
</x-mail::message>
