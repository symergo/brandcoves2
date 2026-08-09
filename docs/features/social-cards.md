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

## Caching

Keyed on the record's `updated_at`, so a retitled product renders once more and never again and no
cache needs clearing at deploy. The response carries a week of `max-age` for platforms that respect
it and an ETag for those that revalidate.

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
| Everything else | — | "Discover products and brands" | brandcoves.com |

A product carried by one shop is not "1 shops" and one with no price is not "from €0"; both are
common enough in a feed that a card built from the happy path would be visibly wrong in public.

## Not done

The Daily Cove has no card of its own yet, so an edition falls back to the generic one. It is the
most shareable page on the site and should carry its theme and date.
