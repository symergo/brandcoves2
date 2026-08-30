# The long product description

A section below the offer table on a product page: the shop's own description of the thing, quoted
and attributed.

- Service: [`App\Services\Catalogue\ProductDescription`](../../app/Services/Catalogue/ProductDescription.php)
- Rendered by: [`Product.tsx`](../../resources/js/Pages/Product.tsx)
- Tests: [`ProductDescriptionTest`](../../tests/Unit/ProductDescriptionTest.php),
  [`ProductPageDescriptionTest`](../../tests/Feature/ProductPageDescriptionTest.php)

## The text was already here

Feed ingestion has stored `products.description` from the start — bol, Coolblue and the rest of Awin,
eBay, Tradedoubler — and `bc_search_vector()` has been weighting it at rank D all along. It was
simply never rendered. So this is a presentation change: no new request, no new column, no new job.

That settles the obvious alternative. Fetching a description live from bol at render time would put
a third party's latency inside our request handler, on the most-crawled page type on the site, to
produce a field we already hold. **A group whose offers carry no description gets no section, and
the fix for that is `bc:ingest`, not a fetch.**

## Which offer's description wins

The longest, among the offers the page has already loaded. Not a quality judgement — length is the
only signal available — but the failure it avoids is real: several merchants carry the same product,
one with a one-line summary and one with the manufacturer's full text, and taking the first offer
would show the summary while the full description sat one row down. Ties break on offer id, so the
page does not change between requests and read as unstable to a crawler.

## What is excluded

| Rejected | Why |
|---|---|
| Amazon | `Source::allowsCatalogueStorage()` is false, so no Amazon row should hold one at all. Filtering here means an upstream bug cannot surface one on a page — invariant 6 covers this field. |
| Under 120 characters | A scrap under a heading looks like a page that failed to load, and the offer titles above already say more. Higher than `Excerpt`'s 30, which guards a single line of card copy rather than a section. |
| The title again | Feeds routinely fill this column with the product name. Compared on a `Str::slug()` key, because the two differ by punctuation and casing far more often than by content. |

Everything is capped at 1800 characters, cut on a word boundary, whole paragraphs dropped first.

## It is plain text, and it keeps its paragraphs

`clean()` turns block boundaries (`<br>`, `</li>`, `</p>`, `</div>`, `</h1-6>`, `</tr>`) into newlines
**before** `strip_tags()`, then decodes entities **after** it. Both orderings matter:

- `strip_tags()` alone concatenates across tags, so `<li>Bluetooth</li><li>ANC</li>` becomes
  `BluetoothANC` — two real words welded into a nonsense one. bol writes its spec bullets as `<ul>`,
  so this is the common case, not an edge one.
- Decoding entities first would hand `strip_tags()` markup it never saw, since an entity can encode a
  bracket.

The output stays plain text and is rendered as `<p>` elements. Rendering merchant HTML would mean
trusting a third-party feed with markup on our page, and at least one Awin advertiser ships
unbalanced tags in this column.

## Attribution is not decoration

"Description supplied by Coolblue." Unattributed, the text reads as this site's editorial voice —
"the best headphones you will ever own" is the shop's opinion and not ours, and it is a page
asserting original prose it did not write. Named, it is a quotation, which is what it is.

## Placement

Below the offer table. Above it, several hundred words of somebody else's marketing copy would push
the one thing this page exists for — who sells it and for how much — off the first screen. Below is
where a shopper who has seen the prices and now wants to know what the thing actually *is* goes
looking.
