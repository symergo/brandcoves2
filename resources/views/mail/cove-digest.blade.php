@php
    app()->setLocale($language);
@endphp

{{--
  The prose and the product list are emitted as HTML blocks, not Markdown.

  A line starting with <p> or <ul> is an HTML block to CommonMark and is passed
  through untouched until the next blank line, which is what we want twice
  over: the paragraphs come from CoveMarkup already escaped and linked, and a
  feed title with a stray * or _ in it must not be read as emphasis. The one
  rule this imposes is that no blank line may fall inside a block — a Blade
  directive on its own line is safe, because PHP swallows the newline after ?>.

  Nothing here comes from Amazon: DigestBuilder has already excluded any product
  we do not hold a name for from a non-Amazon source. That is the PA-API rule,
  which restricts the *content* rather than the destination, so a compliant link
  next to an Amazon title would not launder it. See
  docs/features/amazon-compliance.md.
--}}
<x-mail::message>
# {{ $digest['theme'] }}

@if ($digest['blurb'])
{{ $digest['blurb'] }}
@endif

@foreach ($digest['body'] as $paragraph)
<p>{!! $paragraph !!}</p>

@endforeach
@if (! empty($digest['finds']))
<ul>
@foreach ($digest['finds'] as $find)
<li><a href="{{ url($find['url']) }}"><strong>{{ $find['title'] }}</strong></a>@if ($find['price'] !== null) · {{ Illuminate\Support\Number::currency($find['price'] / 100, $market->currency(), $market->hrefLang()) }}@endif @if ($find['shops'] > 1)· {{ __('site.cove_mail.across_shops', ['count' => $find['shops']]) }}@endif</li>
@endforeach
</ul>

@endif
@if ($digest['omitted'] > 0)
{{ __('site.cove_mail.more_on_page', ['count' => $digest['omitted']]) }}
@endif

<x-mail::button :url="$editionUrl">
{{ __('site.cove_mail.digest_button') }}
</x-mail::button>

---

{{--
  An HTML block again, so the link has to be an anchor. A Markdown link inside
  <small> rendered as literal "[Unsubscribe](https://…)" in every digest sent
  before 2026-09-09: CommonMark does not parse Markdown inside an HTML block.
--}}
<small>
{{ __('site.cove_mail.why_receiving') }}
<a href="{{ $unsubscribeUrl }}">{{ __('site.cove_mail.unsubscribe') }}</a>
</small>

<small>{{ __('site.footer.affiliate') }}</small>
</x-mail::message>
