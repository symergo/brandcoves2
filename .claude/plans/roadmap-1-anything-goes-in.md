# Roadmap step 1: anything goes in

Strategy: [docs/strategy.md](../../docs/strategy.md), engine A and B. Written 2026-09-26.

## Goal

A visitor can put any product into a Cove by pasting a shop link, scanning a barcode we do not
know, or adding a photo, and what they add can become part of the catalogue instead of dying on
their own list.

## Where we start

- A typed item is `wishlist_items` with `source = manual` and a `snapshot_*` title, link and price
  (`ItemSaver::saveManual`, `app/Services/Wishlist/ItemSaver.php:142`). It never shows a picture,
  on purpose: the docblock at `ItemSaver.php:114-133` explains that fetching a pasted link turns a
  list into an SSRF probe, and that a remote image URL on a shared page is a tracking pixel.
- Nothing on the server makes a request to a visitor's link. `SafeExternalUrl` checks the scheme
  only; there is no host or private-address check anywhere, no redirect limit, no size cap.
- The scanner answers `not_found` for an unknown barcode (`ScanController.php:81-95`); in
  `AddProduct.tsx` the digits land in the manual form's title and the barcode itself is not kept.
- No upload handling exists outside one Filament form; no `Storage::` use; no image library.
- A pasted Amazon link in the add-item search is searched as literal text (`WishlistItemController::find`).
- `POST /list-items` has no throttle.

Both objections in the `ItemSaver` docblock are right, and this step answers them rather than
ignoring them: the fetch is guarded (below) and every image is copied to our own storage, so a
shared page never loads a picture from a stranger's server.

## Design

### 1. A guarded fetcher: `App\Services\PageReading\SafeFetch`

One class every visitor-supplied URL goes through. Pure rules, unit tested:

- `https:` only; port 443 only.
- Resolve the host, refuse if **any** address is private, loopback, link-local, reserved or
  multicast (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`, plus IPv6 ranges and
  `169.254.169.254`). Pin the checked address with `CURLOPT_RESOLVE` so the request goes to the
  address we checked, not one a second DNS answer supplies (DNS rebinding).
- Redirects followed by hand, at most 3, each hop re-checked from the top.
- 5 s total timeout, 2 MB body cap (stream and abort), HTML content types only.
- A User-Agent that names GiftCoves and links a page explaining the bot.
- Per-host and per-user rate limits (`RateLimiter` named limiters in `AppServiceProvider`, the
  way `editorial` is defined).

### 2. A pure parser: `App\Services\PageReading\ProductPageParser`

Input HTML, output a `PageProduct` value (title, brand, description, image URLs, price in cents,
currency, availability, GTIN, MPN, canonical URL). Reads in order: JSON-LD `Product`/`Offer`
(including `@graph` and `ProductGroup`), then Open Graph / `product:` meta, then `<title>`. No AI.
Prices go through the existing cents conversion; a currency other than the market's is kept but
not shown as a price. Fixtures: a dozen saved real shop pages under `tests/Fixtures/pages/`.

### 3. A record of what a page said: `page_reads`

`page_reads (id, url_hash unique, url, host, market, status CHECK in (pending, read, failed,
refused), result jsonb, error, fetched_at, timestamps)`. One read per URL per 7 days, shared by
everyone who pastes it, so a popular link costs one request. Pruned like other ops tables.

### 4. The flow

1. `AddProduct.tsx`: when the search box holds a URL, show "Add from this link" instead of
   searching the literal text.
2. `POST /{market}/list-items/from-link` (throttled) validates with `SafeExternalUrl`, then hands
   the link to the **link router** (below). Only a link the router cannot place is fetched: the
   list item is created at once (title = host, `reading` state), the `page_reads` row created or
   reused, and `ReadProductPage` dispatched.

#### The link router: our own data and connectors first, the page last

Owner's rule (2026-09-26): **a link to a site we already hold in the feed database, or have an API
connector for, is resolved through that database or connector, never by fetching and parsing the
page.** Our data is better (prices, stock, offers to compare), it is already allowed, and it costs
the shop nothing. `App\Services\PageReading\LinkRouter::route(url, market)` returns one of: a
catalogue group, a connector result, an Amazon reference, or "unknown, read the page". Pure
lookups, in this order, each unit tested:

1. **Amazon** (`AmazonLink::parse`, which already pulls the ASIN out of every Amazon URL shape):
   never fetched, never scraped. Look the ASIN up in `amazon_products` (what an imported page said
   about it, and the group it maps to by `identity_key`); a mapped group → `saveGroup`; otherwise
   `saveExternal(Source::Amazon, asin)`, which already stores no title, image or price
   (invariant 6). A short link (`amzn.to`) has no ASIN and stays a plain manual item: expanding
   it is a request to Amazon. If an Amazon API connector is added later, it slots in here.
2. **Connectors that address products by id in the URL**: bol (product id in `/p/…/{id}/`),
   eBay (`/itm/{id}`). Look the id up in `products` first (`source`, `external_id`, market); if
   absent, fetch it through the connector's own lookup, which also stores and groups it like any
   live search result (`IncomingGrouper`).
3. **Feed merchants**: match the link's host against the merchants we ingest (Awin, Tradedoubler;
   a host → merchant map derived from stored `merchant_deep_link` hosts), then find the offer by
   its deep link (normalised: scheme, `www.`, tracking parameters and fragment dropped). Found →
   its group. A known merchant but an unknown product is still read from the page, and the offer
   it produces is marked as that merchant's (`web` source, the merchant's host), so matching in
   step 2 can later join it to the feed row.
4. **Anything else**: read the page.

Each connector declares the URL shapes it owns (`Connector::productIdFromUrl(string): ?string`),
so a new connector brings its own routing and the router needs no edit.
3. `ReadProductPage` (queued, `ShouldBeUnique` on the URL hash, 2 tries) fetches, parses, copies
   the image (section 6), then fills every list item still in `reading` state for that URL:
   title, price, image, `gtin`. If a GTIN or brand+title matches a group, it links `group_id`.
4. The list page polls: a small `usePendingItems` hook calls `router.reload({ only: ['items'] })`
   every 2 s while any item is `reading`, for at most 30 s. First polling in the codebase; keep it
   in one hook.
5. A failed read leaves a normal manual item the owner can edit. Nothing breaks.

### 5. Catalogue products without a feed

New `Source::Web` ("read from the shop's own page"). A read that found a title and price writes a
`products` row: `source = web`, `external_id` = canonical URL hash, merchant = the host
(`merchants (web, host)`), `affiliate_url` = the canonical https URL, `status = active`,
`identity_key` from `IdentityResolver`. `ProductGrouper` then treats it like any offer: it joins an
existing group on GTIN or brand+title, or starts one.

`Source::Web` rules: `allowsCatalogueStorage` true, `allowsPriceTracking` false (we read the page
once; we do not poll shops), `requiresPriceTimestamp` true so a web price always shows its date.
Migration re-applies the `source` CHECK on the six tables that carry it, the way
`2026_09_02_000100_ebay_is_a_source.php` did.

**Visibility gate (decide below):** a `web` offer can sit in the catalogue without appearing in
public search until it is grouped with a feed offer, or until a person approves the merchant.

### 6. Images: ours, never hot-linked

`App\Services\Images\ImageStore::ingest(string|UploadedFile)`: size cap 8 MB, sniff the real type
(jpeg, png, webp only), decode and re-encode with GD (strips EXIF, including GPS in a phone photo),
longest side 1600 px, store as `items/{uuid}.webp` on a `media` disk. Used by the page reader and
by uploads. `snapshot_image_url` then points at our own URL; the "no picture on a manual item"
rule is replaced by "no picture from a server we do not control".

### 7. Photo upload

`POST /{market}/list-items/{item}/photo` (owner or editor collaborator, throttled), multipart,
through `ImageStore`. A photo on a manual item only; a catalogue item has its product's image. The
photo is the owner's personal data: it goes when the item, the list or the account goes.

### 8. Unknown barcode

`wishlist_items.gtin` (nullable, validated by `Gtin::normalise`). In `AddProduct` a `not_found`
scan offers "Add it anyway": a manual item with the barcode kept and the title typed. The daily
`GroupProducts` run gains a pass that links manual items whose `gtin` now matches a group in the
list's market, so the item gains a picture and prices the day any shop starts selling it.

## Decide before building

1. **Where images live in production.** The containers' disk does not survive a redeploy. Either a
   Coolify persistent volume mounted at `storage/app/media`, or S3-compatible storage. Needs the
   owner, and the `media` disk points at the answer.
2. **Do pasted products appear in public search?** Recommended: not until grouped with a feed
   offer or the merchant is approved in the admin. Otherwise the search box is one paste away from
   spam.
(Checked 2026-09-26: GD is loaded in Herd's PHP and installed in the production image,
`Dockerfile:88`, so section 6 needs no new dependency.)

## Files

- New: `app/Services/PageReading/{LinkRouter,SafeFetch,ProductPageParser,PageProduct}.php`,
  `productIdFromUrl()` on the bol and eBay connectors, a normalised deep-link column or index on
  `products` for the feed-merchant lookup,
  `app/Jobs/ReadProductPage.php`, `app/Services/Images/ImageStore.php`, `app/Models/PageRead.php`,
  `resources/js/hooks/usePendingItems.ts`.
- Migrations: `page_reads`; `wishlist_items.gtin` + `reading_since`; `Source::Web` CHECK re-apply.
- Change: `ItemSaver` (new `saveFromLink`, docblock rewritten with the reasoning above),
  `WishlistItemController` (from-link, photo, throttle on store), `AddProduct.tsx`,
  `ManualItem.tsx`/`EditManualItem.tsx` (photo), `ProductGrouper` (gtin pass), `Source.php`,
  `config/filesystems.php`, `config/giftcoves.php` (`page_reading` limits).
- Docs: new `docs/features/page-reading.md`, `item-photos.md`; update `wishlists.md`,
  `barcode-scanner.md`, `amazon-compliance.md` (the pasted-link path), `INDEX.md`.

## Verification

- Unit: `SafeFetchTest` (private v4/v6, metadata address, redirect to private, rebinding pin,
  oversized body, non-HTML), `ProductPageParserTest` (fixtures), `ImageStoreTest` (EXIF gone,
  type sniffing, oversize).
- Unit: `LinkRouterTest` (every Amazon URL shape → ASIN; bol and eBay ids; a feed deep link with
  tracking parameters finds its offer; an unknown host falls through).
- Feature: `ReadProductPageTest` with `Http::fake`, `AddFromLinkTest` (Amazon, bol, eBay and feed
  links never reach `SafeFetch` — `Http::assertNotSent` for the shop's host; bol goes through the
  connector; throttle), extend `ManualItemTest`,
  `ScanTest`, `AmazonComplianceTest`.
- By hand on the dev server: paste three real small-shop links, a bol link, an Amazon link; scan
  an unknown barcode; upload a phone photo and check the stored file has no EXIF.
