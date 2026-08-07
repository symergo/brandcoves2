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
