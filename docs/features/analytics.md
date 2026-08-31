# Google Analytics, and the consent it waits for

**Status:** Active on production only. Added 2026-08-31.

GA4 property `G-1D0Z7W35SG`, loaded from `resources/views/app.blade.php` and gated twice: once on
the environment, once on the visitor.

## Why it is gated on `robots_allow`

`App\Support\Analytics::measurementId()` returns null unless `giftcoves.robots_allow` is true, which
today means production and nothing else.

Staging is not a smaller version of the site, it is a *complete duplicate of it* on its own hosts.
Its traffic — crawlers, deploy smoke checks, us clicking about — lands in the same property as real
visitors with nothing to separate them afterwards. GA has no hostname dimension that survives the
comparison a year later; the hits are simply mixed in, and the damage is permanent because you
cannot retroactively unmix them. A single flag that already means "this is the real public site" is
a better gate than a second flag that could drift out of step with the first.

`GA_MEASUREMENT_ID=` (empty) is how an environment opts out even where it is otherwise live. The id
itself is not a secret — it ships in the page source of every site that uses one — so it has a
default in `config/giftcoves.php` rather than being something an environment must supply.

## Why it is gated on consent, server-side

Everything else this site stores is strictly necessary for something the visitor asked for — the
session, the CSRF token, the market they chose, the identifier that lets a list work before there is
an account — and Article 5(3) of the ePrivacy Directive exempts exactly that category. `_ga` is the
first thing here that falls outside it. Both published markets are EU (BE, NL), so this is not
optional.

**The gate is a cookie read in PHP, not a check the tag does after loading.** A tag that loads and
then decides has already fetched a script from Google and already had its chance. `App\Support\
CookieConsent::stored()` answers the question; `app.blade.php` is the only place it is asked.

**Three states, not two.** `null` means nobody has been asked, and is the only state that shows the
banner. A refusal is written down like an acceptance, or every page load re-asks somebody who
already said no — which the EDPB reads as nagging a consent out of someone rather than receiving one.

**Six months, both ways.** Long enough not to pester a regular visitor, short enough that consent
expires rather than being given once and honoured forever. Deliberately shorter than `bc_market`'s
year: a market choice is a convenience the visitor gets, consent is a permission we get.

## The alternative that was considered and rejected

Google Consent Mode v2 with `analytics_storage: 'denied'` by default would have needed no banner at
all: gtag.js loads, sets no cookie, and sends cookieless pings that GA models into aggregate figures.
Legally clean, and about a tenth of the work.

It was rejected because the numbers it produces are modelled rather than counted — no unique users,
no returning-visitor split, no reliable session stitching — and the first question this site will
ask of analytics is whether people come back. A banner that is genuinely easy to refuse buys real
data from the people who agree, and the ones who do not are simply not measured, which is the
correct outcome rather than a loss to be engineered around.

## The banner

`resources/js/Components/CookieBanner.tsx`, rendered from `SiteLayout` and shown only when
`analytics.id !== null` — so it never appears on staging or locally. A banner asking permission for
something that was never going to load is theatre, and it teaches people to dismiss the ones that
mean something.

Both buttons are the same size and shape; the accent is on Accept because it is the affirmative
action, not because refusing should feel like a mistake. Nothing is blocked, nothing is overlaid,
and ignoring the bar is a valid outcome that counts as no.

Accepting posts to `/consent` **and** loads the tag client-side from `resources/js/analytics.ts`, so
the page somebody agreed on is the page that gets reported. Waiting for the next request would throw
away the landing page, which is usually the most interesting one we have.

Withdrawing is the **Cookies** link in the footer. It posts `choice=reset`, which clears the cookie
and puts the question back rather than hiding a toggle in a settings page — withdrawal has to be as
easy as consent was, and this is the cheapest honest version of that.

## SPA page views

Inertia swaps the page component and pushes history without a document reload, so the inline snippet
reports exactly one page view per visit and GA calls every session a bounce. `resources/js/app.tsx`
listens on `router.on('navigate')` and reports the rest.

Two details that are easy to get wrong: the first fire is skipped, because `gtag('config', …)` has
already counted the landing page and double-counting inflates the most important page on the site;
and the report is deferred one frame, because Inertia's `<Head>` writes `document.title` during the
render that follows the event, so reading it immediately attributes every page view to the title of
the page being *left*.

## What is turned off in code

`cookie_expires: 33696000` (13 months, the CNIL ceiling) rather than GA4's two-year default — a
two-year cookie outlives the six-month consent that permitted it, which would leave us holding an
identifier under a permission that had lapsed. Plus `allow_google_signals: false` and
`allow_ad_personalization_signals: false`.

All three are set in the tag rather than in the GA4 property, because the privacy page makes these
claims in writing and a checkbox in somebody's admin console is not a commitment this repo can keep.
`AnalyticsTest` asserts each one for the same reason.

## The privacy pages moved with it

`resources/legal/{en,nl}/privacy.md` previously said "No Google Analytics", "we set no analytics
cookie", and "there is no cookie banner because there is nothing here that needs consent". All three
became false the moment the tag existed. Both pages now name Google as a processor, describe what
the tag collects, list the analytics cookie in the retention table, and explain the banner. `updated`
moved to 2026-08-31, which is what tells subscribers the policy changed.

There is no French or Spanish privacy page; those markets fall back to English, unchanged by this.
The banner copy itself exists in all four languages.

## Files

| | |
|---|---|
| `config/giftcoves.php` | `google_analytics_id` |
| `app/Support/Analytics.php` | is the tag on here, and under which id |
| `app/Support/CookieConsent.php` | has this visitor agreed |
| `app/Http/Controllers/CookieConsentController.php` | records the answer |
| `resources/views/app.blade.php` | the tag, for a visitor who accepted |
| `resources/js/analytics.ts` | client-side load, and the SPA page view |
| `resources/js/Components/CookieBanner.tsx` | the question |
| `tests/Feature/AnalyticsTest.php` | both gates, both directions |
