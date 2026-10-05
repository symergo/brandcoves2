@php
    use App\Enums\Market;
    use App\Services\Seo\PageMeta;
    use App\Support\Analytics;
    use App\Support\CookieConsent;
    use App\Support\CurrentMarket;

    $market = app(CurrentMarket::class)->get();
    $pageMeta = app(PageMeta::class);
    $meta = $pageMeta->toArray();
    $canonical = $meta['canonical'] ?? url(request()->path());
    $indexable = config('giftcoves.robots_allow') && $meta['robots'] === null;

    /*
      The tag renders on every page, and stores nothing until the visitor agrees.

      This is Consent Mode v2, adopted 2026-09-17 at the owner's request, and it
      reverses what stood here before: the script itself used to be withheld
      until somebody accepted. That kept Google out of the page entirely, and it
      also made the site look untagged to Google's own scanner, which arrives
      with no cookies — Google Ads reported the tag as missing and could
      attribute no conversion at all.

      What it costs, stated plainly because the privacy page has to say it too:
      a visitor who has not agreed now causes a cookieless ping to Google (their
      IP and the page address reach it) instead of nothing, and their visit is
      modelled rather than counted. What it does not cost: no storage, on this
      site or in their browser, until the answer is yes — that is what the
      denied defaults below mean, and they are set before gtag.js is requested.
    */
    $analyticsId = Analytics::measurementId();
    $analyticsGranted = CookieConsent::stored(request()) === true;

    /*
      The Google Ads account, for the outbound-click conversion.

      One gtag.js serves both: the library is fetched once and each account is
      configured on it. `$tagId` is what the script is fetched under, and it is
      the measurement id when there is one — an environment with Ads configured
      and analytics switched off still gets a working tag rather than none.
    */
    $adsAccount = Analytics::adsAccount();
    $tagId = $analyticsId ?? $adsAccount;
@endphp
<!DOCTYPE html>
{{-- lang comes from the market, not the app locale: nl-BE and nl-NL are the same
     language but different markets, and search engines need the distinction. --}}
<html lang="{{ $market->hrefLang() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Empty on a page from the anonymous page cache: that page is the same for
         every signed-out visitor and cannot carry one visitor's token. The
         browser asks GET /csrf before its first write instead
         (resources/js/http.ts, ensureCsrfToken). --}}
    <meta name="csrf-token" content="{{ \App\Http\Middleware\CacheAnonymousPage::servesAnonymously(request()) ? '' : csrf_token() }}">

    @if ($tagId !== null)
        {{-- The consent defaults come FIRST, in a blocking inline script, before
             gtag.js is requested. That order is the whole guarantee: a default
             that arrives after the library has started is a default that arrives
             too late, and Google's own documentation is explicit that the
             command must be queued before the tag loads. --}}
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}

            /*
              Everything denied until the visitor says otherwise. Denied does not
              mean "do not measure" — it means store nothing: no cookie is
              written and no identifier is kept, so the ping that goes out
              carries no way to recognise this person again.

              wait_for_update gives the client-side update from the banner half a
              second to arrive before anything is sent, so a visitor who accepts
              immediately is counted properly rather than as a stranger.
            */
            gtag('consent', 'default', {
                ad_storage: 'denied',
                ad_user_data: 'denied',
                ad_personalization: 'denied',
                analytics_storage: 'denied',
                wait_for_update: 500,
            });

            @if ($analyticsGranted)
                /*
                  Replayed from the cookie for somebody who already said yes, in
                  the same breath as the default, so a returning visitor is never
                  measured as a cookieless one.

                  ad_personalization stays denied even here. The privacy page
                  promises in writing that nothing measured on this site becomes
                  an advertising audience, and conversion measurement does not
                  need it — ad_storage and ad_user_data are what let Google Ads
                  attribute a conversion to a click. Granting it is the one line
                  to change if remarketing is ever wanted, and the privacy page
                  changes with it.
                */
                gtag('consent', 'update', {
                    ad_storage: 'granted',
                    ad_user_data: 'granted',
                    ad_personalization: 'denied',
                    analytics_storage: 'granted',
                });
            @endif
        </script>

        {{-- Google tag (gtag.js). As high in <head> as the meta tags allow, so
             the request is in flight before the stylesheet blocks rendering;
             `async` keeps it off the critical path either way.

             This fires the first page view only. Inertia navigations never
             reload the document, so every page after the first is reported from
             resources/js/app.tsx — without that, GA would record one hit per
             visit and call every session a bounce. --}}
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($tagId) }}"></script>
        <script>
            gtag('js', new Date());

            @if ($analyticsId !== null)

            /*
              Thirteen months, not the two years GA4 defaults to. That is the
              CNIL's ceiling for a measurement cookie, it is the number the
              privacy page commits to, and a two-year cookie outlives the
              six-month consent that permitted it — which would leave us holding
              an identifier under a permission that had already lapsed.
            */
            gtag('config', @js($analyticsId), {
                cookie_expires: 33696000,
                /*
                  The two settings the privacy page promises are off, set here
                  rather than left to the GA4 property — a checkbox in somebody's
                  admin console is not a commitment this repo can keep, and the
                  policy makes the claim in writing. Google Signals off means no
                  cross-device advertising profile; ad personalization off means
                  nothing measured here is reusable as an ad audience.
                */
                allow_google_signals: false,
                allow_ad_personalization_signals: false,
            });
            @endif

            @if ($adsAccount !== null)
                /*
                  The Google Ads account, configured on the same tag.

                  An event may only report to an account the tag knows about, so
                  without this line the outbound-click conversion is sent
                  nowhere — and silently, which is the part that costs an
                  afternoon. The event itself is fired from
                  resources/js/analytics.ts when somebody leaves for a shop.

                  Storage is still governed by the consent defaults above:
                  before a yes this reports without cookies, and
                  ad_personalization stays denied either way.
                */
                gtag('config', @js($adsAccount));
            @endif
        </script>
    @endif

    {{-- Icons. The SVG is what modern browsers take, and it is the one that
         stays sharp on a high-density tab strip; the .ico is the fallback every
         browser asks for by name whether or not it is declared. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/svg+xml" href="{{ asset('icons/giftcoves.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/giftcoves-512.png') }}">

    {{-- The cream the page actually paints. It said #12232B, a dark teal that
         appears nowhere in the palette, so the browser chrome on Android and the
         iOS status bar were a colour the site does not contain. --}}
    <meta name="theme-color" content="#fff8f6">

    {{-- When a theme switch is built it needs one more thing here: an inline
         script, in <head>, that stamps the stored choice onto <html> before
         anything paints. It cannot live in the React bundle — that runs after
         first paint, so a visitor who chose dark would get a full frame of cream
         on every navigation, which is the flash that makes a toggle feel broken.
         The dark tokens it would switch on are already in app.css. --}}

    @if ($indexable)
        <meta name="robots" content="index, follow, max-image-preview:large">
    @else
        {{-- Staging must never be indexed: a full duplicate of the site would
             outrank the real one on some queries. Filtered and paginated search
             pages set their own noindex to keep thin variants out of the index.

             The environment wins outright. This used to fall back to the page's
             own value, so a controller asking for `index, follow` explicitly
             (the surprise page does) was honoured on staging — one crawlable
             page on a host whose robots.txt says the opposite. A page's value
             is read only where indexing is allowed at all. --}}
        <meta name="robots" content="{{ config('giftcoves.robots_allow') ? ($meta['robots'] ?? 'noindex, nofollow') : 'noindex, nofollow' }}">
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
    {{-- A controller that already holds the row hands its alternates over
         through PageMeta; everything else is resolved from the path. --}}
    @php($alternates = $meta['alternates'] ?? app(App\Services\Seo\Alternates::class)->for(request()->path(), $market))
    @foreach ($alternates as $hrefLang => $href)
        <link rel="alternate" hreflang="{{ $hrefLang }}" href="{{ $href }}">
    @endforeach
    @if ($default = app(App\Services\Seo\Alternates::class)->defaultFor($alternates))
        <link rel="alternate" hreflang="x-default" href="{{ $default }}">
    @endif

    {{-- Social cards. Scrapers do not execute JavaScript, so these have to be
         server-rendered — a React-set meta tag is invisible to every one of them. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="GiftCoves">
    <meta property="og:locale" content="{{ str_replace('-', '_', $market->hrefLang()) }}">
    <meta property="og:url" content="{{ $canonical }}">
    {{-- social_* is the page's own title and description unless it set a
         separate pair for shared links (PageMeta::social(), the homepage). --}}
    @isset($meta['social_title'])
        <meta property="og:title" content="{{ $meta['social_title'] }}">
        <meta name="twitter:title" content="{{ $meta['social_title'] }}">
    @endisset
    @isset($meta['social_description'])
        <meta property="og:description" content="{{ $meta['social_description'] }}">
        <meta name="twitter:description" content="{{ $meta['social_description'] }}">
    @endisset
    @isset($meta['image'])
        <meta property="og:image" content="{{ $meta['image'] }}">
        {{-- Declared so a platform can reserve the right space before it has
             fetched the file. Every image a page sets is one of our own cards,
             so the dimensions are known rather than guessed. --}}
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ $meta['image'] }}">
    @else
        {{-- The generic card rather than nothing. A shared link with no image
             renders as a bare grey rectangle in every chat app, which reads as a
             broken page. Full width, because a page worth sharing deserves the
             large card even when it has no picture of its own. --}}
        @php($fallbackCard = \App\Services\Seo\SocialCard::versioned(url($market->value.'/og/default.png')))
        <meta property="og:image" content="{{ $fallbackCard }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ $fallbackCard }}">
    @endisset

    {{-- The highest-leverage SEO on the site: a Product with an AggregateOffer
         is what makes a listing show "€329.99 to €349.00 from 2 sellers". --}}
    @foreach ($pageMeta->jsonLd() as $schema)
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endforeach

    {{-- Inter is self-hosted (resources/css/app.css says why). The regular
         weight is on every line of body text, so it is asked for now, beside
         the stylesheet, instead of after the stylesheet has arrived and been
         parsed. Only that one: preloading all three weights would make the
         browser fetch bold text's file before it knows a page has any.
         `crossorigin` is required on a font preload even from our own origin;
         without it the preloaded copy is fetched in the wrong mode and thrown
         away. rescue(): a preload hint is not worth a 500 if the manifest has
         no entry for it. --}}
    @php($interRegular = rescue(fn () => \Illuminate\Support\Facades\Vite::asset('resources/fonts/inter/inter-latin-400-normal.woff2'), null, false))
    @if ($interRegular)
        <link rel="preload" href="{{ $interRegular }}" as="font" type="font/woff2" crossorigin>
    @endif

    {{-- Most product images come from bol's CDN. Opening that connection while
         the HTML is still arriving saves the DNS, TCP and TLS round trips from
         the first product photo, which is often the largest thing on the
         page. No `crossorigin`: images are fetched without CORS, and a
         preconnect in the wrong mode is a connection nobody uses.

         With the image proxy on (docs/features/image-proxy.md), search cards
         and the product page's main picture come from our own origin; this
         stays for the rails, Coves and lists that still link bol directly. --}}
    <link rel="preconnect" href="https://media.s-bol.com">

    {{-- The page's own chunk, alongside the app entry, as the Laravel React
         starter kit does. Pages are split per component (app.tsx imports them
         lazily), so without this the browser learns which chunk it needs only
         after app.js has downloaded and run: two round trips in a row before
         the page can hydrate. Named here, Vite emits a modulepreload for it
         and its imports, and AddLinkHeadersForPreloadedAssets repeats them as
         Link headers.

         Guarded by a file check because an entry Vite cannot find in the
         manifest throws, and a preload hint is not worth a 500. Every
         component rendered today has its file; the guard is for the one
         that is renamed next. --}}
    @php($pageEntry = 'resources/js/Pages/'.$page['component'].'.tsx')
    @viteReactRefresh
    @vite(array_values(array_filter([
        'resources/css/app.css',
        'resources/js/app.tsx',
        is_file(base_path($pageEntry)) ? $pageEntry : null,
    ])))
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
