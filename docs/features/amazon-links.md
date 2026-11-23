# "Bekijk op Amazon": the same product on Amazon, without a price

Owner, 2026-11-23: "add Amazon links to the product offers but without a price, just a 'check on
amazon' link", and "add it to search as well, but without price". Code:
`App\Services\Amazon\AmazonMatches`, `AsinDataClient`, `App\Jobs\LookUpAmazonMatches`. Tests:
`tests/Feature/AmazonMatchesTest.php`.

## What a visitor sees

- **Product page:** a last row among the offers, "Amazon · Prijs en voorraad op Amazon", with a
  "Bekijk op Amazon" button. No price, no stock.
- **Search result card:** a row under "Alle aanbiedingen": "Bekijk op Amazon ↗", for a product
  already matched.
- **Search page, "Ook op Amazon":** Amazon's own products for the term, up to eight, under ours
  and above the pager: name, Amazon's picture, a tagged `/dp/ASIN` link. No price. See below.
- Only on be-nl and be-fr (amazon.com.be) and nl-nl (amazon.nl), and only for a product with a
  barcode that Amazon carries on that storefront. The link is `https://www.<storefront>/dp/<ASIN>`
  with our Associates tag, `rel="sponsored noopener nofollow"`, a new tab.

## How a product gets its link

ASIN Data API (Traject Data) answers one question per barcode: which ASIN is this on that
storefront. `type=product&amazon_domain=…&gtin=…`, one credit. The key is `ASINDATA_API_KEY`
(locally `.claude/asindataapi.api`, gitignored).

1. A **product page** a person opens reads its match and queues `LookUpAmazonMatches` for its
   barcode when it was never asked or asked more than `recheck_days` (60) ago. A search page
   shows the matches it has but queues nothing (owner, 2026-11-23: "don't do a batch"): a page of
   results would queue thirty barcodes at once. A crawler queues nothing: credits go to what people look at. A
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
API, and an Amazon price must be current and may not be kept. ASIN Data API reads Amazon's pages.
The ASIN is not content: it is the product's number, and a direct link to it is what Amazon asks
Associates links to be. So `amazon_matches` has the storefront, the barcode, the ASIN and the
date, nothing else (`AmazonMatchesTest` asserts the columns), and invariant 6 holds: nothing from
Amazon enters `products`, search ranking, a wishlist, a chart or an email. See
amazon-compliance.md.

**The search section goes further, by the owner's decision (2026-11-23):** it shows Amazon's
titles and pictures, read by ASIN Data API rather than PA-API. The price was the line drawn: never
shown, never read. The risk was raised before it was built: Amazon may treat product content not
from PA-API as a breach of the Associates agreement, and the penalty is the account behind every
tag. If PA-API access arrives, the search section should move to it.

## "Ook op Amazon": Amazon's search under ours (2026-11-23)

Owner: "just do a search with the api on amazon when a search is made". `App\Services\Amazon\AmazonSearch`.

- The search page carries a deferred prop `amazon` in a group of its own. The browser asks for it
  right after the page is shown; that request is answered at the top of `SearchController`,
  before any of the search work, so it costs a cache read or one API call and nothing else.
- ASIN Data API `type=search` (one credit) for the term on the market's storefront. Sponsored
  results are dropped: for "lego technic" the first ones were robot kits. Kept: ASIN, title,
  Amazon's picture address, for `search_cache_days` (7) per storefront and term, in the cache,
  never in the catalogue. At most `searches_per_day` (100, `ASINDATA_SEARCHES_PER_DAY`) new
  terms a day. A failed search is remembered for an hour. Never for a crawler, a gift search, a
  landing page or page 2 and later.
- Pictures are Amazon's own addresses, shown as they are: never copied or proxied (the image
  proxy refuses Amazon). No price, no stock, no rating.
- **Saved** (owner, same day: "save the amazon products to database, without price"): each
  result goes to `amazon_products` (ASIN, `classified_title`, `image_url`, the storefront in
  `seen_in_locales`, `imported_from = asindata:search`; never a price, there is no column), and
  the term's answer as shown to `amazon_searches` (storefront, language, term, items, searched_at). A term is
  answered from there for `search_cache_days`, also after a cache flush. A title or picture a
  person imported from the product's own page is not overwritten by the search's.
- `exclude_sponsored=true` drops Amazon's ads on their side; the `sponsored` check stays as a
  second guard.
- **Language.** Asked with `language` per market: be-nl `nl_BE`, be-fr `fr_BE`, nl-nl `nl_NL`.
  amazon.com.be answers French without it. The parameter is not in the manual
  (docs.trajectdata.com/asindataapi/product-data-api/parameters/search, the reference the owner
  named); the owner supplied it and a test search came back in Dutch. Because be-nl and be-fr
  share amazon.com.be, `amazon_searches` is keyed on storefront, language and term, and keeps
  the titles with the search, not only on the product.
- **Order.** `sort_by=bestseller_rankings` (owner's decision, 2026-11-23; `ASINDATA_SEARCH_SORT`).
  The manual's listed values are most_recent, price_low_to_high, price_high_to_low, featured and
  average_review; bestseller_rankings is not among them, so the API may treat it as Amazon's own
  order. `direct_search` is left off, so Amazon still corrects a typo in the term.

**Credits.** On 2026-11-23 the account showed `topup_credits_remaining: 89`. Every search and
every barcode lookup costs one, so the daily caps should follow the plan bought, not the defaults.
