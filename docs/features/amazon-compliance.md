---
name: Amazon Associates compliance
area: Core / Legal
status: Active — enforced in code, Amazon itself deferred to Phase 8
date_added: 2026-08-07
---

# Amazon Associates compliance

Every feature audited against the Amazon Associates Programme Operating
Agreement and the Product Advertising API License Agreement.

> **Read this before building anything that touches Amazon data.**
>
> **And verify it.** These agreements change, differ by marketplace (amazon.nl,
> .fr, .es, .de each have their own local terms), and this audit reflects the
> rules as understood at the time of writing, not legal advice. The items marked
> **VERIFY** are ones where the exact wording materially changes what we may
> build, and should be checked against the current agreement text before Phase 8.
>
> The stake is real: a violation costs the Associates account, and with it every
> Amazon link on the site retroactively.

## The line: storage is fine, *tracking as a feature* is not

The distinction that matters most here, and the one easiest to get wrong in
either direction:

> **Amazon prices may be stored. A price-tracking feature may not be offered.**

Recording a price is not the restricted act. Building a product on top of that
record — a price chart, a "cheapest it has ever been" claim, a price-drop alert
— is. So the gate sits on the **read** side, not the write side:

| | Stored? | Shown as a tracking feature? |
|---|---|---|
| Awin, bol | yes | yes |
| Amazon | **yes** | **no** |

`Source::allowsPriceStorage()` is true everywhere. `allowsPriceTracking()` is
false for Amazon and gates the sparkline, the "typical price" claim, discount
badges derived from history, and alerts.

This also means internal uses of stored Amazon prices remain available —
detecting that a feed moved, or spotting a merchant with a permanently inflated
reference price — because none of those are a visitor-facing feature.

## The other restrictions that shape the product

| # | Restriction | What it constrains |
|---|---|---|
| 1 | No price-tracking feature | Sparkline, price alerts, historical claims |
| 2 | No Associates links or product content in email | Alerts, digests, shared-list mails |
| 3 | No mirroring the catalogue | Storing Amazon products in our search index |
| 4 | Displayed prices need an "as of" time and a disclaimer | Bare price display |
| 5 | Links must go to Amazon, unobscured | Aggressive redirect/cloaking patterns |

Restrictions 1 and 2 together are why **price alerts are not offered on Amazon
offers**, which is what prompted this audit — an alert is price tracking with an
email attached, so it collides with both.

## Feature-by-feature

| Feature | Amazon | Awin / bol | How it is enforced |
|---|---|---|---|
| **Search & offer comparison** | ✅ live only | ✅ | `allowsCatalogueStorage()` — Amazon offers are never upserted into `products` |
| **Cheapest-offer comparison** | ✅ at render | ✅ | Amazon prices come from a live fetch, never a stored aggregate |
| **Price recording** | ✅ stored | ✅ | `allowsPriceStorage()` — true everywhere; storage is not the restricted act |
| **Price history / sparkline** | ❌ not shown | ✅ | `allowsPriceTracking()` — filtered on read, so Amazon prices exist but never appear in the chart |
| **"Typical price" / discount badge** | ❌ not shown | ✅ | Derived from history, so excluded by the same read-side gate |
| **Price-drop alerts** | ❌ | ✅ | `allowsPriceAlerts()` — the alert button is not offered |
| **Back-in-stock alerts** | ❌ | ✅ | Same. Availability is pricing data under the 24-hour rule |
| **Wishlists** | ⚠️ decision only | ✅ | Store the ASIN; re-fetch title, price and image at render |
| **Wishlist daily refresh** | ❌ no email | ✅ | Refresh is fine; notifying by email is not |
| **Daily Picks** | ⚠️ decision only | ✅ | Already designed this way: persist the ASIN plus scoring metadata, re-fetch live, hide on failure |
| **Daily Picks email digest** | ❌ exclude | ✅ | Digest must contain no Amazon items at all |
| **Buying guides** | ⚠️ live prices | ✅ | An Amazon item in a guide needs its price fetched at render, not baked into the page |
| **Gift Whisperer** | ⚠️ live only | ✅ | Scoring may use live data; the giftable index may not store Amazon rows |
| **Barcode scanner** | ✅ live lookup | ✅ | A live lookup by EAN is exactly the permitted pattern |
| **Outbound links** | ✅ direct anchor | ✅ via redirector | `requiresDirectLink()` — see below |
| **Price "as of" disclaimer** | ✅ shown | n/a | `requiresPriceTimestamp()` |
| **Search-result caching** | ⚠️ 15 min | ✅ | `maxPriceAgeSeconds()` — well inside the 24-hour limit |

## Outbound links: two paths, one per source

**Resolved — Amazon links are direct anchors.** Amazon requires Associates links
to be unobscured, so its offers never touch the redirector.

| Source | Link | Click recorded by |
|---|---|---|
| Awin, bol | `/{market}/go/{offer}` → 302 | Server, in the redirector |
| **Amazon** | **direct `<a href>` to amazon.xx** | `navigator.sendBeacon` on mousedown |

`Source::requiresDirectLink()` decides, and `Product::outboundUrl()` returns the
right one. Three things make this safe rather than merely different:

- **The redirector refuses a direct-link source outright** (404). A hand-built
  or cached `/go/` URL must not quietly still work — that is exactly how the
  requirement gets violated months later by someone who did not know about it.
- **`outboundUrl()` returns null when the stored URL is unsafe**, so the view
  renders no link at all. The redirector normally performs the scheme check;
  on the direct path there is nothing between us and the browser.
- **The beacon is fire-and-forget.** It fires on mousedown so it is queued
  before the browser starts unloading, uses `sendBeacon` because a normal fetch
  usually dies with the page, and returns 204. A failure loses one analytics row
  rather than a sale.

The beacon route is CSRF-exempt, deliberately: `sendBeacon` cannot set headers.
It writes an analytics row and nothing else, is rate-limited, and the worst a
forged request can do is skew a click count.

**Trade-off accepted:** beacon-recorded clicks are less reliable than redirector
ones — ad blockers and privacy settings drop some. Amazon click counts will
therefore under-report relative to Awin and bol. Events carry `via: beacon` so
the two are never compared as if they were the same measurement.

## Rules that bind us even with Amazon disabled

Two apply to how the code is *built*, not to what runs today, and are far
cheaper to honour now than to retrofit:

- **Email must be source-aware.** Every mail we send has to filter its contents
  by `allowsEmail()`. Building the alert system Amazon-blind and adding a filter
  later means auditing every template.
- **Price history must be source-aware at write time.** Excluding Amazon rows
  when the sparkline is *read* is not enough — the retention breach is the
  storage, not the display.

## Required disclosures

- **Affiliate disclosure** on every page carrying affiliate links. Present in
  the footer and beneath the offer table.
- **Amazon-specific wording** is mandated and differs per marketplace — the
  English form is "As an Amazon Associate I earn from qualifying purchases."
  Must be added when Amazon is enabled, in each market's language.
- **Price timestamp and disclaimer** next to any Amazon price
  (`requiresPriceTimestamp()`), stating the price may have changed.

## What changed in the code because of this audit

1. `Source` gained `allowsPriceHistory()`, `allowsPriceAlerts()`,
   `allowsEmail()`, `requiresPriceTimestamp()` and `maxPriceAgeSeconds()`
   alongside the existing `allowsCatalogueStorage()`.
2. `OfferUpserter` skips `price_history` for sources that disallow it.
3. Alert buttons are only offered where `allowsPriceAlerts()`.
4. Mailables filter their contents through `allowsEmail()`.
5. Tests assert each of the above, so an Amazon offer cannot acquire a price
   history or an alert by accident.

## Other programmes

**bol** permits caching within its terms and has no equivalent email
restriction, but its rate limits are strict — see
[ingestion.md](ingestion.md).

**Awin** rules are per-advertiser rather than network-wide. Some advertisers
prohibit price comparison or restrict voucher content. Worth capturing per feed
if a merchant objects; nothing enforces it today.
