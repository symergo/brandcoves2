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
| **Search & offer comparison** | ✅ live only | ✅ | `allowsCatalogueStorage()`, in `SearchService::pullLiveResults()` — Amazon offers are never upserted into `products` |
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

## Email: the link is not the only restriction

The question that comes up every time someone designs a digest: *if we do not
link to Amazon, can we include the product?*

**No.** There are two separate rules and dropping the link clears only one.

| | What it restricts | Does linking to our own page help? |
|---|---|---|
| Associates Operating Agreement | Special Links in email | Yes — no Associates link, no breach of this one |
| PA-API licence | *Product Advertising Content* — titles, images, prices, review data from Amazon's APIs — displayed anywhere but your site or app | **No.** The restriction is on the content, not the destination |

So an email carrying an Amazon product's title, image and price breaches the
second rule even when every link points at brandcoves.com. That is why
`Source::allowsEmail()` is documented as *"product data **or** links"* rather
than just links.

### What this means for the Cove digest

It pushes strongly toward **a teaser that links to the edition**, not the
edition rendered into an email:

- our own editorial line, the observance, a few
  non-Amazon finds
- one link: *see today's Cove*

Amazon items then live only on the page, live-fetched as already designed, and
the email has no Amazon surface at all. The alternative — full edition in
email with Amazon items stripped — is defensible on the same reading, but every
future template inherits a filter someone has to remember. A digest with
nothing to filter cannot be got wrong later.

### Link by EAN, not by offer

The neat resolution: **an email link points at our own search for the barcode**,
not at a product page and certainly not at Amazon.

```
/{market}/search?q={ean}
```

`SearchService` treats a GTIN as an exact identity *and* queries the live
sources, so the reader lands on the full comparison — Amazon included, fetched
live, on our page where it is licensed to appear. The email itself carries a
number and our own words.

This works because of what the email does **not** contain, so the rule that
makes it safe has to be stated as a rule:

> The email may name a product only when we hold that name from a **non-Amazon**
> source — an Awin feed, bol, or our own editorial. An Amazon-only item gets a
> link and no description, or is left out.

A title lifted from PA-API is Product Advertising Content wherever it appears,
and putting it next to a compliant link does not launder it.

> Terms change and the EU Associates Programme differs from the US one. Read
> the current agreement for the locales in use before the first send. This
> document records reasoning, not legal advice.

## Across locales

The same ASIN is the same physical product on every Amazon storefront. That
single fact splits the data cleanly in two, and the split is what the schema
encodes:

| | Where it lives | Why |
|---|---|---|
| **The decision** — ASIN, giftability, surprise score, category | `amazon_products`, **one row, no market column** | A property of the product, not the storefront. Ours, and expensive to derive. |
| **What a shopper reads** — price, description, image, availability | Nowhere. Fetched live per locale at render | Mirroring is not permitted, and these genuinely differ per storefront. |

**Classification runs once per ASIN.** Doing it per locale would spend five
times the compute to produce five answers that ought to be identical — and
would not be, because the classifier reads the title and the title is
translated. `classified_locale` records which storefront's title was used, so a
surprising verdict is traceable.

### Every locale is offered; only one of them competes

A visitor in any market can select any locale. Someone who reads French and
lives in Belgium may still want the German price, and hiding it is us guessing
on their behalf about something they can see for themselves.

But **only the primary locale's price may enter the comparison**
(`AmazonLocale::isComparableIn()`). A foreign price carries foreign tax and
cross-border shipping; letting it win "cheapest" would beat a local offer the
shopper can actually act on. That is exactly the failure market-scoped identity
exists to prevent, so a foreign locale is always a labelled extra — *"also on
amazon.de for €40"* — and never a row in the offer table.

Belgium is the awkward case and the reason `primaryFor()` is a method rather
than a flat map: `amazon.com.be` exists but is thin, so `be-nl` defaults to
`amazon.nl` and `be-fr` to `amazon.fr`. The selector lets a visitor disagree.

`seen_in_locales` orders the tabs and is explicitly **a hint, not a fact** —
refreshed on a schedule, while the price is always fetched live. A stale entry
therefore costs one empty tab, never a wrong number.

## Other programmes

**bol** permits caching within its terms and has no equivalent email
restriction, but its rate limits are strict — see
[ingestion.md](ingestion.md).

**Awin** rules are per-advertiser rather than network-wide. Some advertisers
prohibit price comparison or restrict voucher content. Worth capturing per feed
if a merchant objects; nothing enforces it today.
