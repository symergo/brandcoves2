@php
    use App\Enums\Market;
    use App\Services\Seo\PageMeta;
    use App\Support\CurrentMarket;

    $market = app(CurrentMarket::class)->get();
    $pageMeta = app(PageMeta::class);
    $meta = $pageMeta->toArray();
    $canonical = $meta['canonical'] ?? url(request()->path());
    $indexable = config('brandcoves.robots_allow') && $meta['robots'] === null;
@endphp
<!DOCTYPE html>
{{-- lang comes from the market, not the app locale: nl-BE and nl-NL are the same
     language but different markets, and search engines need the distinction. --}}
<html lang="{{ $market->hrefLang() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Icons. The SVG is what modern browsers take, and it is the one that
         stays sharp on a high-density tab strip; the .ico is the fallback every
         browser asks for by name whether or not it is declared. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/svg+xml" href="{{ asset('icons/brandcoves.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/brandcoves-512.png') }}">
    <meta name="theme-color" content="#12232B">

    @if ($indexable)
        <meta name="robots" content="index, follow, max-image-preview:large">
    @else
        {{-- Staging must never be indexed: a full duplicate of the site would
             outrank the real one on some queries. Filtered and paginated search
             pages set their own noindex to keep thin variants out of the index. --}}
        <meta name="robots" content="{{ $meta['robots'] ?? 'noindex, nofollow' }}">
    @endif

    @isset($meta['description'])
        <meta name="description" content="{{ $meta['description'] }}">
    @endisset

    <link rel="canonical" href="{{ $canonical }}">

    {{-- Tell search engines the same page exists in the other markets. Without
         these, five market versions of one page compete with each other and the
         wrong language can rank in the wrong country.

         Resolved rather than guessed: product identity is market-scoped, so
         swapping the URL segment on a product page produces four links to
         404s — and Google discards a whole hreflang cluster when one member is
         missing, taking the genuine translations down with it. See
         App\Services\Seo\Alternates. --}}
    @php($alternates = app(App\Services\Seo\Alternates::class)->for(request()->path(), $market))
    @foreach ($alternates as $hrefLang => $href)
        <link rel="alternate" hreflang="{{ $hrefLang }}" href="{{ $href }}">
    @endforeach
    @if ($default = app(App\Services\Seo\Alternates::class)->defaultFor($alternates))
        <link rel="alternate" hreflang="x-default" href="{{ $default }}">
    @endif

    {{-- Social cards. Scrapers do not execute JavaScript, so these have to be
         server-rendered — a React-set meta tag is invisible to every one of them. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Brandcoves">
    <meta property="og:locale" content="{{ str_replace('-', '_', $market->hrefLang()) }}">
    <meta property="og:url" content="{{ $canonical }}">
    @isset($meta['title'])
        <meta property="og:title" content="{{ $meta['title'] }}">
        <meta name="twitter:title" content="{{ $meta['title'] }}">
    @endisset
    @isset($meta['description'])
        <meta property="og:description" content="{{ $meta['description'] }}">
        <meta name="twitter:description" content="{{ $meta['description'] }}">
    @endisset
    @isset($meta['image'])
        <meta property="og:image" content="{{ $meta['image'] }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ $meta['image'] }}">
    @else
        {{-- The logo rather than nothing. A shared link with no image renders as
             a bare grey rectangle in every chat app, which reads as a broken
             page; the mark at least says whose page it is. Square, so it pairs
             with the small `summary` card and not the wide one. --}}
        <meta property="og:image" content="{{ asset('icons/brandcoves-512.png') }}">
        <meta name="twitter:card" content="summary">
        <meta name="twitter:image" content="{{ asset('icons/brandcoves-512.png') }}">
    @endisset

    {{-- The highest-leverage SEO on the site: a Product with an AggregateOffer
         is what makes a listing show "€329.99 to €349.00 from 2 sellers". --}}
    @foreach ($pageMeta->jsonLd() as $schema)
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endforeach

    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet">

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
