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

@if ($section['drops'] !== [])
<div class="table"><table>
<thead><tr><th align="left">{{ __('site.list_watch.col_product') }}</th><th align="right">{{ __('site.list_watch.col_was') }}</th><th align="right">{{ __('site.list_watch.col_now') }}</th><th align="right">{{ __('site.list_watch.col_change') }}</th></tr></thead>
<tbody>
@foreach ($section['drops'] as $line)
<tr><td><a href="{{ $line['url'] }}">{{ $line['title'] }}</a></td><td align="right">{{ $line['was'] === null ? '' : $money($line['was']) }}</td><td align="right"><strong>{{ $line['now'] === null ? '' : $money($line['now']) }}</strong></td><td align="right">{{ $line['percent'] === null ? '' : '-'.$line['percent'].'%' }}</td></tr>
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

<small>{{ __('site.footer.affiliate') }}</small>
</x-mail::message>
