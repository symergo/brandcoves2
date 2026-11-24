# "Bekijk op Amazon": the same product on Amazon, without a price

Owner, 2026-11-23: "add Amazon links to the product offers but without a price, just a 'check on
amazon' link", and "add it to search as well, but without price". Code:
`App\Services\Amazon\AmazonMatches`, `AsinDataClient`, `App\Jobs\LookUpAmazonMatches`. Tests:
`tests/Feature/AmazonMatchesTest.php`.

## What a visitor sees

- **Product page:** the first row among the offers (owner, 2026-11-23), drawn like every other shop's (owner, 2026-11-23):
  Amazon's favicon, the storefront as the shop's name (amazon.com.be), the title under it (Amazon's
  when a search saved one, else ours), "Prijs en voorraad op Amazon" where the others show a price,
  and the very same button, "Naar de winkel". No price, no stock.
- **Search result card:** a row under "Alle aanbiedingen": "Bekijk op Amazon ↗", for a product
  already matched.
- **Search page, mixed in** (owner, 2026-11-23: "mix the amazon products with the other", by
  relevance in every order and filter): up to eight of Amazon's own products for the term appear
  as cards among ours, `AmazonProductCard`. Neither list scores the other, so each keeps its own
  order and the two are interleaved evenly: Amazon's best near our best, and so on. The card is
  built like ours, with "Bekijk op Amazon · amazon.com.be" where ours show the price and shop.
  Amazon's answer arrives a moment after the page, so the cards after each Amazon card shift once;
  the owner chose that over making every search wait. A price sort or filter does not move them.
- **By-store view:** an Amazon column first (owner, same day), drawn like the shops' columns, each
  row saying "Bekijk op Amazon" where the others show a price.
- Only on be-nl and be-fr (amazon.com.be) and nl-nl (amazon.nl), and only for a product with a
  barcode that Amazon carries on that storefront. The link is `https://www.<storefront>/dp/<ASIN>`
  with our Associates tag, `rel="sponsored noopener nofollow"`, a new tab.

## How a product gets its link

ASIN Data API (Traject Data) answers one question per barcode: which ASIN is this on that
storefront. `type=product&amazon_domain=…&gtin=…`, one credit. The key is `ASINDATA_API_KEY`
(locally `.claude/asindataapi.api`, gitignored).

1. A **product page** a person opens asks for its Amazon row in a follow-up request its own
   JavaScript makes after the page is shown (2026-11-23): a scraper fetching only the HTML, which
   had spent the day's 1,000 lookups by 01:06 from residential proxies, queues nothing. That
   request reads the match and queues `LookUpAmazonMatches` for its
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

## Amazon's search among ours (2026-11-23)

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

## Amazon in the shop filter and on brand pages (owner, 2026-11-24)

"Amazon is not in the filters and not on the brand search pages."

- **Shop filter.** Amazon is listed first among the shops, by its storefront (amazon.com.be,
  amazon.nl), wherever its products could join the page (`amazonShop`). Its value in `merchant`
  is the word `amazon` (`AmazonSearch::FILTER`), not an id: Amazon is no merchant of ours, so
  `SearchQuery` drops it and our results ignore it; `AmazonSearch::filtersWith()` hands it back
  so the box and its chip stay ticked. No shop ticked: Amazon mixed in, as before. Other shops
  ticked and Amazon not: Amazon's products are not asked (`shownWith()`), since that is asking
  for those shops. Only Amazon ticked: the grid shows Amazon's cards alone and no page buttons,
  which page through ours.
- **One brand ticked** puts the brand in front of the Amazon term ("Sony koptelefoon"), unless the
  term names it already (`termFor()`). Amazon has no brand filter we can pass, and the term alone
  mixed every brand's products into a Sony-filtered list. Several brands: the term alone.
- **Brand pages** (`/brand/{slug}`, a search filtered to the brand) get the same deferred `amazon`
  prop and shop-filter entry, asked for the brand name plus any narrowing words, mixed into the
  grid by `mixWithAmazon()`, the function the search page uses. Not on a Brand Cove (an article,
  no grid). Never for a crawler, and every new term counts against `searches_per_day`.

Price filters, "discounted only" and sorting still leave Amazon mixed in: the owner chose "mixed by
relevance everywhere" on 2026-11-23. Amazon's cards carry no price, so a price filter cannot judge them.
