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
first thing here that falls outside it. Every published market is in the EU (Belgium, the
Netherlands, and the English market under the EU flag), so this is not optional.

**Since 2026-09-17 the gate is Consent Mode, not the absence of the script.** The tag loads on every
page with `ad_storage`, `ad_user_data`, `ad_personalization` and `analytics_storage` all denied, and
a stored `granted` cookie is replayed as a `consent update` in the same inline block, before gtag.js
is requested. `App\Support\CookieConsent::stored()` still answers the question and `app.blade.php` is
still the only place it is asked — what changed is what a "no" produces: a loaded tag that stores
nothing, rather than no tag.

Why it changed is in [the section below](#google-ads-reported-the-tag-as-missing-2026-09-17). What it
costs: a visitor who has not agreed causes a cookieless ping (IP and page address) to Google instead
of nothing at all, and is modelled rather than counted. Both privacy pages say so, in those words.

**Three states, not two.** `null` means nobody has been asked, and is the only state that shows the
banner. A refusal is written down like an acceptance, or every page load re-asks somebody who
already said no — which the EDPB reads as nagging a consent out of someone rather than receiving one.

**Six months, both ways.** Long enough not to pester a regular visitor, short enough that consent
expires rather than being given once and honoured forever. Deliberately shorter than `bc_market`'s
year: a market choice is a convenience the visitor gets, consent is a permission we get.

## Google Ads reported the tag as missing (2026-09-17)

Consent Mode v2 was considered when this was built and rejected: modelled numbers rather than
counted ones, no unique users, no reliable returning-visitor split. That reasoning still holds, and
it was overridden for a reason it did not anticipate.

**Google's own scanner arrives with no cookies.** It therefore met the state every unanswered
visitor met — a page with no tag in it — and Google Ads reported "Google tag missing", with no
conversion attributable to any campaign. Measured on 2026-09-17: the live page served no
`googletagmanager.com` script at all, while `AnalyticsTest` proved the tag rendered correctly for a
consenting visitor. Nothing was broken; the site was simply invisible to the checker.

**The banner stays.** Consent Mode is not a way to stop asking — storage still waits for a yes, the
cookie is still what decides, and refusing is still one click. The change is only that the script is
present while the answer is no.

**`ad_personalization` stays denied even after a yes.** The privacy pages promise that nothing
measured here becomes an advertising audience, and conversion measurement does not need it:
`ad_storage` and `ad_user_data` are what let Ads attribute a conversion to a click. Granting it is
one line in `app.blade.php` and one paragraph in each privacy page, and neither should move without
the other.

**What Ads still cannot see.** Click-outs — the only revenue signal this site has — are recorded in
`events` by `ClickOutController` and are not sent to GA4, so the only conversion Google knows about
is `sign_up`. A tag that loads is a precondition for conversion tracking, not conversion tracking.

## The banner

`resources/js/Components/CookieBanner.tsx`, rendered from `SiteLayout` and shown only when
`analytics.id !== null` — so it never appears on staging or locally. A banner asking permission for
something that was never going to load is theatre, and it teaches people to dismiss the ones that
mean something.

Both buttons are the same size and shape; the accent is on Accept because it is the affirmative
action, not because refusing should feel like a mistake. Nothing is blocked, nothing is overlaid,
and ignoring the bar is a valid outcome that counts as no.

Accepting posts to `/consent` **and** calls `updateConsent()` in `resources/js/analytics.ts`, so the
page somebody agreed on is the page that gets reported. Waiting for the next request would throw away
the landing page, which is usually the most interesting one we have.

Declining sends the update too, which is not redundant: somebody who accepted earlier in the same
page's life and then withdrew needs the denial to take effect now rather than on the next document
load. No page view is re-sent on acceptance — the tag already reported this page when it loaded, and
firing a second one would count the landing page twice.

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

## Registrations as a conversion

Added 2026-09-12. A new account is the one action on the site worth counting as a conversion, and
GA4 counts conversions from events, so a first sign-in fires GA4's standard `sign_up` event with a
`method` of `google` or `email`. Marking it as a key event is done once in the GA4 property
(Admin, Events, toggle "Mark as key event" on `sign_up`); nothing in the repo can do that part.

How it travels: both auth callbacks (`GoogleController::callback()`, `MagicLinkController::consume()`)
know whether they just created the account, and only then call `Registration::record()`, which flashes
`signed_up` with the method (and mails the owner, see [auth.md](auth.md)).
`HandleInertiaRequests` shares it as `flash.signUp`, so it exists for exactly one request, the page
after the redirect. `app.tsx` reads it in the same `navigate` handler that reports SPA page views
and calls `reportSignUp()`.

Three things that are deliberate:

- **The server decides what a registration is.** The client could have compared `auth.user` before
  and after, but a sign-in that merges anonymous work, or a Google login onto an account made by
  magic link, looks the same from the browser. Only the callback knows `User::create` ran.
- **Once per page lifetime, whatever the props say.** The flash is gone after one request, but
  Inertia restores page props from history on back/forward, and a visitor who signs up and presses
  back would otherwise convert twice. `reportSignUp()` keeps a flag.
- **Consent is not checked again.** `window.gtag` exists only if the shell rendered the tag or the
  banner loaded it. A visitor who refused, or has not answered, produces no event, and the registration
  is simply not counted. That undercount is the price of the consent model and is accepted.

Both sign-in paths are covered in `AuthTest`: a first sign-in carries the note and a second one does
not, and the shared prop is null again on the next request.

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
| `resources/js/analytics.ts` | the consent update, the SPA page view, and the `sign_up` event |
| `app/Http/Controllers/Auth/GoogleController.php`, `MagicLinkController.php` | flash `signed_up` on a first sign-in |
| `app/Http/Middleware/HandleInertiaRequests.php` | shares it as `flash.signUp` |
| `resources/js/Components/CookieBanner.tsx` | the question |
| `tests/Feature/AnalyticsTest.php` | both gates, both directions |
| `tests/Feature/AuthTest.php` | the sign-up note on a first sign-in, and not on a second |
