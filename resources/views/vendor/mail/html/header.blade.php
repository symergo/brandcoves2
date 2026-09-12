@props(['url'])
{{--
  The mail masthead: the GiftCoves mark and the name, always.

  Laravel's stock header prints config('app.name'), which is whatever APP_NAME
  says in that environment and "Laravel" wherever it is unset; the first list
  price digest rendered with that word above it. The brand is not an
  environment variable, so it is written here once. The PNG rather than the
  SVG because mail clients render SVG unreliably, and an absolute URL because
  a mail has no origin to resolve a relative one against.
--}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<img src="{{ url('/icons/giftcoves-512.png') }}" class="logo" alt="GiftCoves" width="48" height="48">
<span class="brand">GiftCoves</span>
</a>
</td>
</tr>
