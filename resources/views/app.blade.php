<!DOCTYPE html>
{{-- lang comes from the market, not the app locale: nl-BE and nl-NL are the same
     language but different markets, and search engines need the distinction. --}}
<html lang="{{ app(App\Support\CurrentMarket::class)->get()->hrefLang() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @unless (config('brandcoves.robots_allow'))
        {{-- Staging must never be indexed, and a duplicate of the whole site in
             the index would outrank the real one on some queries. --}}
        <meta name="robots" content="noindex, nofollow">
    @endunless

    {{-- Tell search engines the same page exists in the other markets. Without
         these, five market versions of one page compete with each other and the
         wrong language can rank in the wrong country. x-default points at the
         pan-European English market for everyone we do not serve directly. --}}
    @foreach (App\Enums\Market::cases() as $alternate)
        <link rel="alternate"
              hreflang="{{ $alternate->hrefLang() }}"
              href="{{ url(App\Support\CurrentMarket::swapMarketInPath(request()->path(), $alternate)) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default"
          href="{{ url(App\Support\CurrentMarket::swapMarketInPath(request()->path(), App\Enums\Market::En)) }}">

    <link rel="canonical" href="{{ url(request()->path()) }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600" rel="stylesheet">

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
