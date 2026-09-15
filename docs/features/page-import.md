---
name: Page import (browser extension)
area: Ingestion / Editorial
status: Active — bol into the catalogue, Amazon into the decision store
date_added: 2026-09-14
---

# Page import

Browse a shelf on bol.com or Amazon, click once, and the products on the screen
are in GiftCoves. The extension lives in [`extension/`](../../extension/) and
talks to two endpoints on the editorial API.

It exists because **choosing is the expensive part**. Ingestion can fetch
anything; knowing that *this* shelf is worth having is a judgement, and until now
there was no way to act on it except to guess a search term that would surface
the same products and hope the connector agreed.

---

## The two halves are not symmetrical, and that is the whole design

| | bol | Amazon |
|---|---|---|
| What the page supplies | the id, and a title | everything |
| What is stored | whatever **bol's API** says | what the **page** said |
| Lands in | `products` → `product_groups` — the catalogue | `amazon_products` — the ASIN decision store |
| Price | from the API, in cents | **never** |
| Endpoint | `POST /api/editorial/import/bol` | `POST /api/editorial/import/amazon` |

**bol: the page chooses, the API states the facts.** The request carries product
ids and titles and nothing else is believed. Every price, image, category and
affiliate link is re-fetched from bol. That matters most for the affiliate URL:
an untracked link works perfectly for the visitor and pays nobody, and it is the
one bug invisible from the outside. Built by the connector from bol's own
product URL, it cannot be forged by a client.

**Amazon: there is nothing to re-fetch from.** No Product Advertising API is
configured, so the page is the only source and the scraped fields are stored as
stated, with `imported_from` recording which page said so. That is a weaker
guarantee, bounded by where the rows go — see [Amazon](#amazon) below.

---

## Getting from a page to a catalogue record

Harder than it looks on bol, and the reason is worth writing down because it is
not discoverable from the documentation. Measured against the live API on
2026-09-14:

- `GET /products/{id}` on bol's catalogue API is keyed on the **EAN**. A
  `bolProductId` — the number in every bol.com product URL — answers `400`
  with `must match "^\d{13}$"`.
- Searching for a `bolProductId` as a search term returns **zero** results.
- Without `country-code` the request is a 400; without `include-offer` and
  `include-image` the response carries no price and no picture, so the row is
  stored unbuyable and unrenderable.

So the id in the URL, the one thing every page reliably carries, cannot be
looked up directly. Two routes out, in this order:

1. **The barcode**, when the page gave one — a product page carries it in its
   specifications and its structured data. Exact, and one call.
2. **The title**, otherwise. Search bol for it, then keep the result whose
   `bolProductId` equals the one scraped from the link.

The second deserves care. The search only *enumerates* candidates; the id
comparison decides. On bol one product name routinely covers six colourways, so
"a product with this name" is not "the product somebody pointed at" — and a
near-miss must produce nothing rather than a plausible substitute nobody would
catch. Measured at rank 1 for 8 of 8 titles from a live listing page.

> **This fixed a live bug.** `BolConnector::fetchById()` passed no parameters at
> all, so every call 400'd and returned null. Its only caller is
> `RefreshWishlistedProducts`, which means **no watched bol product's price has
> ever been refreshed** — the job added on 2026-09-06 to fix exactly that
> silently did nothing. The connector now has `fetchByEan()`, and `fetchById()`
> routes an EAN-shaped id to it and returns null for anything else rather than
> issuing a request that cannot succeed. The refresh job still passes
> `products.external_id`, a `bolProductId`, so **it is still not fixed** — it
> needs to pass `products.ean`. Left alone deliberately: it is a different
> feature with its own tests, and quietly changing a compliance-adjacent job
> while building an import is how two bugs become one confusing diff.

---

## Amazon

Amazon is the source with the tightest rules on this site, and this feature
moves a line. Both facts belong in the same paragraph.

**What changed.** The owner asked on 2026-09-14 for the extension to save an
Amazon page's title, description, image link, category and EAN/UPC — and said
explicitly *not the price, yet*. `amazon_products` gained `description`,
`image_url`, `ean`, `imported_from` and `imported_at`. Its original migration
says "no price, no availability, no description, no image", and two of those
four are no longer true.

**What did not change, and why the price is held back**, is recorded in the audit, which is the
document to change before widening any of this:
[amazon-compliance.md](amazon-compliance.md#an-exception-was-made-on-2026-09-14-and-here-is-exactly-how-far-it-goes).
In short: `Source::allowsCatalogueStorage()` is still false, so nothing an import writes reaches
`products`, search, a wishlist, a chart or an email; and a price has no scraper field, no validator
rule and no column.

### The barcode is the point

`amazon_products.identity_key` has been described as "the bridge" from an ASIN
to the product group other shops' offers hang off since the table was created in
August, and **nothing has ever been able to fill it**, because Amazon publishes
no barcodes through any interface this site had. A product page prints one.

With it, `SearchController::productFor()` — written for this and never able to
do it — resolves a pasted Amazon link to a real GiftCoves product page.

In practice Amazon prints an EAN on a minority of listings. Of four amazon.nl
product pages sampled on 2026-09-14, one had a barcode. A 12-digit UPC is
widened to 13 with a leading zero, because Amazon prints both and treating them
as different numbers would file one product under two identities.

---

## What the scrapers learned from real pages

Every rule below replaced something that looked right and was wrong. They are
recorded because each was found by running the scraper against the live site,
and none is recoverable from the code.

**An image's alt text is not a product name.** It reads like the best
candidate — long, descriptive, always present — and bol writes it to describe
the *picture*: "Casque supra-auriculaire Sony noir, tourné vers la gauche avec
coussinets visibles". Being the longest string on the card, it wins any "prefer
the longest" rule, and then becomes the term the server searches bol with. So
title sources are ranked by how deliberately each is a name, and a picture's
description is not among them.

**`innerText`, not `textContent`.** A badge is a child element with no
whitespace around it, so `textContent` returned "The Witchexclusieve bol editie"
for a book called *The Witch*.

**Colour swatches are product links whose text is a colour and a price.**
"Blanc47,92". Rejected by a glued-price pattern. On a French results page
nineteen of fifty-one products are these; they are real, distinct products that
a listing page gives us no way to name, so they are dropped and **counted**, and
the popup says how many. A shelf that offers thirty-two of fifty-one should say
why rather than just look short.

**Amazon's detail labels are prose.** A loose `\b(fabrikant)\b` match against
"Stopgezet door fabrikant" returned `Nee` as the brand of a pair of Sony
headphones, and "Aanbevolen leeftijd van fabrikant" returned `18 - 99 jaar` as
the brand of a LEGO set. Labels are anchored and matched exactly.

**Ids come out of hrefs, not markup.** `/p/<slug>/<id>/` on bol and
`/dp/<asin>/` on Amazon are what the sites' own URLs are made of. Class names
and `data-test` hooks change without warning and a scraper pinned to them fails
by finding nothing, which is indistinguishable from an empty page.

**A product page is not a shelf.** Both sites surround one product with dozens
of recommendations — nineteen on the bol page sampled, and more on Amazon. The
page's own product is listed first and is the only one ticked; the rest are one
click away. The keyboard shortcut uses the same rule, so it is the button
without the popup rather than a second, greedier behaviour.

### Page types verified against the live sites, 2026-09-14

| Site | Page | Result |
|---|---|---|
| bol | search results | 33 products, all named |
| bol | category `/l/` | 53 products, all named |
| bol | campaign `/cmp/` | 9 |
| bol | brand `/b/` | 23 |
| bol | deals | 32 |
| bol | product page | the product, plus 18 recommendations |
| Amazon | search results | 156 |
| Amazon | bestsellers | 30 |
| Amazon | new releases | 38 |
| Amazon | product page | the product, with brand, category, description, image |

Found nothing: bol `/lijstjes/` (wish lists — **login required**, untestable
headlessly, and the products on it are ordinary `/p/` links so it is expected to
work in a real session), bol `/pb/`, `/sdl/` and `/inf/`, and Amazon `/deals`,
whose cards are client-rendered with no product links in the DOM at all.

Two "failures" during development were neither: invented URLs that 404'd. If a
page scans empty, check that it is the page you think it is before touching the
scraper.

---

## Shape of a request

```http
POST /api/editorial/import/bol
Authorization: Bearer bc_…

{ "market": "nl-nl",
  "products": [{ "productId": "9200000032872507", "ean": null, "title": "Sony MDR-ZX110 …" }] }
```

Every id sent comes back with a status, because a curator who exports twenty and
gets fourteen needs to know which six and why:

| Status | Means |
|---|---|
| `imported` | written and attached to a product group |
| `updated` | Amazon only — the ASIN was already known |
| `unavailable` | bol knows it and is not selling it. There is no availability flag in bol's payload; the presence of an offer block *is* the signal |
| `unresolved` | neither the barcode nor the title matched the page's id |
| `ungrouped` | written, but no identity could be resolved, so it has no card |
| `rate_limited` | bol is refusing; retry in a minute |
| `skipped` | Amazon only — no title on the page, and nothing can be decided about a nameless ASIN |

Both endpoints need `editorial.write` and cap a request at 60 products. The
client splits a larger page into batches of 60 and reports progress, because an
Amazon search page yields over 150 and a bol import spends roughly a second per
product asking bol about it.

---

## Where the code is

| Thing | File |
|---|---|
| The endpoints | `app/Http/Controllers/Api/CatalogueImportController.php` |
| bol resolution and write | `app/Services/Ingestion/BolPageImport.php` |
| Amazon write | `app/Services/Ingestion/AmazonPageImport.php` |
| Grouping shared with live search | `app/Services/Ingestion/IncomingGrouper.php` |
| bol product lookup by barcode | `app/Services/Connectors/Bol/BolConnector::fetchByEan()` |
| The extension | `extension/` — `scan-bol.js`, `scan-amazon.js`, `popup.js` |
| Tests | `tests/Feature/BolPageImportTest.php`, `AmazonPageImportTest.php`, `IncomingGrouperTest.php` |

`IncomingGrouper` was lifted out of `SearchService::groupIncoming()` rather than
copied. Both write offers outside a feed run and both need the new rows countable
before the page that triggered them renders; two copies would be two answers to
"when may an offer join a group", and a wrong merge lets a foreign price
masquerade as the cheapest — the worst bug this site has.

### It finds the offers by source, id and market (2026-09-14)

`attach()` takes the offers themselves, not a list of their ids, and works one
source at a time. Its three statements used to find the incoming rows by id and
market alone, and the only index on the id is `(source, external_id, market)`: a
filter that leaves out the leading column cannot seek into it, so Postgres walked
the whole index for each statement. That was the entire cost of a first search
for a term on production, from 7 to over 45 seconds while a repeat took about one,
and it is why this import timed out on the evening it shipped. Measured there on a
warm cache, one lookup took 698 ms as it was and 0.7 ms with the source added.

The source is also the exact key, since an id is only unique within its source.
Before, another shop's row that shared the number was swept in too, grouped
outside the nightly run with its group's counts rewritten. Only the step that
finds the touched groups is scoped to the source; the counts after it still
include every shop's offers, so a bol offer arriving next to an eBay one still
makes the card say two shops. `IncomingGrouperTest` pins all three: the join and
its counts, the other shop's row left alone, and a batch from two sources.

None of this affects how a list shows its products. A list item points at its
group by id, and grouping was always limited to one market.
