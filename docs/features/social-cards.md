---
name: Social cards
area: SEO / Brand
status: Active
date_added: 2026-08-09
---

# Social cards

The 1200×630 image a shared link turns into. Drawn per page from the record behind it, in the
market's own language: a kicker, the headline, an amber rule, and one line of substance.

Before this, pages either offered the square logo or a product photograph, and a page with neither
rendered as a bare grey rectangle in every chat app — which reads as a broken link, not a plain one.

## Drawn, not templated

[OgImage](../../app/Services/Seo/OgImage.php) rasterises with GD. The text is the whole point, so a
fixed background with a logo on it would not have been worth serving.

The mark is drawn rather than loaded: the SVG needs a rasteriser GD does not have, and the PNG would
mean resampling a bitmap that is the wrong size for this canvas. The arc angles are the fiddly part
and they are written down in the method — the SVG sweeps −55° to +55° with the gap facing right, and
GD measures clockwise from three o'clock on a y-down canvas, so the same sweep is 55° → 305°.

Type is Inter, committed at [resources/fonts](../../resources/fonts) under the SIL Open Font License
(the licence text sits beside it). GD needs a TTF on disk; the site's webfonts are woff2 and cannot
be used here.

The headline is **bottom-anchored above the rule** so a one-word brand and a three-line product
title share a baseline, and it **shrinks from 60pt to 42pt before it truncates**, because a
seventy-character feed title reads better small than clipped.

## Typographic, never photographic

No product photography goes into a card, and this is a rule rather than a shortcut.

A social image is fetched and **cached indefinitely by every platform that renders it**. Compositing
a merchant's photo into one and serving it from our domain is mirroring their image on
infrastructure we do not control: for Amazon that breaks invariant 6 outright, and for every other
source it is a licence question nobody has answered. Hotlinking instead would put a third-party
fetch inside our own request, which is exactly what the [Amazon link
parser](amazon-link-paste.md) refuses to do.

So the card carries the mark, the type, and numbers we computed ourselves.

## Text comes from records, never from the request

Every route takes an id or a slug and reads its own text. **Nothing renders a string from a query
parameter.** An endpoint that draws arbitrary words onto a Brandcoves-branded card is an
impersonation tool with a URL, and our own domain would be serving the screenshot. A test asserts
that adding `?title=` changes nothing about the bytes.

Product cards are market-scoped like everything else: a card served under `/be-nl/` that describes a
Dutch product would put a price nobody can pay into a Belgian timeline.

## Caching, and the two things it got wrong first

Cached for a month, keyed on **the exact text the card will draw** and **the commit that rendered
it**. The response carries a week of `max-age` for platforms that respect it and an ETag for those
that revalidate.

The commit half was learned the hard way, in public, within an hour of shipping. A card's content
comes from the row *and* from the code and language files that lay it out, and only the first of
those moves `updated_at`. The Daily Cove card first rendered during a container swap, picked up a
translation key that build did not have, and cached `SITE.OG.DAILY` in 24pt amber for thirty days —
with no way to clear it short of shell access to the box.

Keying on the commit costs one re-render per card per deploy, which nothing but a scraper will ever
notice, and makes a bad card impossible to inherit across a deploy.

The other half **was** the record's `updated_at`, which was the obvious choice and wrong twice over.

*Too coarse.* Laravel's `timestamps()` is `timestamp(0)` in Postgres — whole seconds, confirmed on
the column rather than assumed. Two edits inside one second are one value, so the second edit served
the first edit's card for the full month. This surfaced as a test that failed on a fast machine and
passed on a slow one, which is the worst way for a real bug to announce itself.

*Too narrow.* Half of what a card draws is not on the record at all. `merchant_count` and `min_price`
are aggregates, and a guide's footnote counts its items; ingestion writes those in bulk without
touching the parent row. A product that went from five shops to fourteen went on announcing five to
everyone it was shared with, for thirty days.

Hashing the drawn strings is exact in both directions: the key moves when the card would look
different, and never otherwise. An edit that touches only a column the card never shows — a
recategorisation, say — correctly re-serves the cached bytes instead of redrawing the catalogue.

Throttled at 60/minute despite the cache: a flood of requests for products nobody has shared is a
flood of cache *misses*, and each miss rasterises type at 1200×630.

## The failure that would have been silent

GD compiled without FreeType does not error in any way a caller notices. `imagettftext` emits a
warning, draws nothing, and returns a perfectly valid PNG — so the cards would have been teal
rectangles with a logo, served as 200s, and cached for a week by every platform that fetched one
before anybody saw it.

`OgImage::render()` therefore checks the font is usable and throws if it is not. A 500 on an image
endpoint is the better failure: scrapers retry, and the error lands in the log on the first request
rather than in a screenshot a week later. The runtime image installs gd via `install-php-extensions`,
which enables FreeType, so the guard is insurance against a future edit to that line.

## Where each card comes from

| Page | Kicker | Headline | Footnote |
|---|---|---|---|
| Product | Product | The product title | "14 shops · from € 279,00", or as much as is true |
| Guide | Buying guide | The guide title | How many products it covers |
| Brand | Brand | The brand name | Products and shops |
| Daily Cove | The Daily Cove | The edition's theme | The edition's date |
| Everything else | — | "Discover products and brands" | brandcoves.com |

A product carried by one shop is not "1 shops" and one with no price is not "from €0"; both are
common enough in a feed that a card built from the happy path would be visibly wrong in public.

## Two things the Daily Cove card does differently

**It is addressed by date, never by "today".** A platform caches the card it fetched when a link was
first posted, and `/daily` is a different edition every morning. The page therefore points at the
dated image even at its own undated URL, so a post from last Tuesday keeps showing last Tuesday's
theme.

**It applies the page's rules.** The Daily Cove refuses a future date because guessing tomorrow's
puzzle by URL would be an obvious hole in a daily game, and it refuses an unpublished edition. A card
is a URL that renders the theme in 60pt type, so it refuses both as well. An image endpoint that
skips a page's access rules is that page's access rules with an extension on the end.
