@php
    // Localised to the owner's market, not the app default.
    app()->setLocale($language);
    $money = fn (int $cents) => Illuminate\Support\Number::currency($cents / 100, $market->currency(), $market->hrefLang());
@endphp

{{--
  The tables and lists are emitted as HTML blocks, not Markdown, for the same
  reason cove-digest.blade.php does it: a product title with a stray * or _ or
  | in it must not be read as emphasis or a column break. The one rule this
  imposes is that no blank line may fall inside a block, so the rows are kept
  on consecutive lines and the Blade directives sit on lines of their own.

  `<div class="table">` is what <x-mail::table> wraps a Markdown table in, so
  the theme's table styles apply here as they would there.

  Nothing here comes from Amazon: the prices were chosen by
  AlertEligibility::trackablePrice(), which reads trackable sources only.
--}}
<x-mail::message>
# {{ __('site.list_watch.mail_heading') }}

{{ __('site.list_watch.mail_intro') }}

@foreach ($sections as $section)
<p><strong><a href="{{ $section['url'] }}">{{ $section['title'] }}</a></strong></p>

{{--
  One cell per product: the name across the full width, the prices under it.
  Four columns (product, was, now, change) left the name two words wide on a
  phone and broke it mid-word (owner's screenshot, 2026-09-30). The old price
  struck through says "was" in every language, so the rows need no header.
--}}
@if ($section['drops'] !== [])
<div class="table"><table>
<tbody>
@foreach ($section['drops'] as $line)
<tr><td><a href="{{ $line['url'] }}">{{ Illuminate\Support\Str::limit($line['title'], 90) }}</a><br><span style="font-size: 17px;"><strong>{{ $line['now'] === null ? '' : $money($line['now']) }}</strong></span>@if ($line['was'] !== null)&nbsp;&nbsp;<s style="color: #8a8078;">{{ $money($line['was']) }}</s>@endif @if ($line['percent'] !== null)&nbsp;&nbsp;<span style="color: #3f7a4a; font-weight: 600;">-{{ $line['percent'] }}%</span>@endif</td></tr>
@endforeach
</tbody>
</table></div>

@endif
@if ($section['back'] !== [])
<p><strong>{{ __('site.list_watch.back_heading') }}</strong></p>
<ul>
@foreach ($section['back'] as $line)
<li><a href="{{ $line['url'] }}">{{ $line['title'] }}</a>@if ($line['now'] !== null) · {{ $money($line['now']) }}@endif</li>
@endforeach
</ul>

@endif
@endforeach
<x-mail::button :url="$buttonUrl">
{{ __($buttonKey) }}
</x-mail::button>

<small>{{ __('site.list_watch.mail_why') }}</small>
</x-mail::message>
