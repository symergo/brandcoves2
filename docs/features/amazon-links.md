# "Bekijk op Amazon": the same product on Amazon, without a price

Owner, 2026-11-23: "add Amazon links to the product offers but without a price, just a 'check on
amazon' link", and "add it to search as well, but without price". Code:
`App\Services\Amazon\AmazonMatches`, `AsinDataClient`, `App\Jobs\LookUpAmazonMatches`. Tests:
`tests/Feature/AmazonMatchesTest.php`.

## What a visitor sees

- **Product page:** a last row among the offers, "Amazon · Prijs en voorraad op Amazon", with a
  "Bekijk op Amazon" button. No price, no stock.
- **Search result card:** a row under "Alle aanbiedingen": "Bekijk op Amazon ↗".
- Only on be-nl and be-fr (amazon.com.be) and nl-nl (amazon.nl), and only for a product with a
  barcode that Amazon carries on that storefront. The link is `https://www.<storefront>/dp/<ASIN>`
  with our Associates tag, `rel="sponsored noopener nofollow"`, a new tab.

## How a product gets its link

ASIN Data API (Traject Data) answers one question per barcode: which ASIN is this on that
storefront. `type=product&amazon_domain=…&gtin=…`, one credit. The key is `ASINDATA_API_KEY`
(locally `.claude/asindataapi.api`, gitignored).

1. A page a **person** opens (product page, a page of search results) reads the matches it has,
   one query, and queues `LookUpAmazonMatches` for the barcodes never asked or asked more than
   `recheck_days` (60) ago. A crawler queues nothing: credits go to what people look at. A
   barcode queued in the last hour is not queued again.
2. The job asks the API, at most `lookups_per_day` (default 1000, `ASINDATA_LOOKUPS_PER_DAY`)
   across both storefronts, counted from today's rows. It keeps the answer in `amazon_matches`:
   the ASIN, or null for "Amazon has no such product", so that question is not paid for again
   for 60 days.
3. Measured on 2026-11-23: found is 200 with `product.asin`; not on Amazon is 200 with no product
   and a message; a hiccup is 500 "you have not been charged" and writes nothing; a bad key (401),
   no credits (402) or too fast (429) stops all lookups until tomorrow.

Because pages are cached for anonymous visitors (CacheAnonymousPage), a barcode is queued when a
person's visit builds the page, not on every view. That is enough: popular products are built
often.

## Why only the ASIN (and why not a price)

Amazon's Associates agreement lets product data on display come from its own Product Advertising
API, and an Amazon price must be current and may not be kept. ASIN Data API reads Amazon's pages,
so its prices, titles and pictures are exactly what may not be shown. The ASIN is not content: it
is the product's number, and a direct link to it is what Amazon asks Associates links to be. So
`amazon_matches` has the storefront, the barcode, the ASIN and the date, nothing else
(`AmazonMatchesTest` asserts the columns), and invariant 6 holds: nothing from Amazon enters
`products`, search ranking, a wishlist, a chart or an email. See amazon-compliance.md.
