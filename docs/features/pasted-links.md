---
name: Pasted links, photos and unknown barcodes
area: Wishlist / Ingestion
status: Active
date_added: 2026-09-26
---

# Pasted links, photos and unknown barcodes

**Anything can go on a list: paste a link from any shop, scan a barcode nobody sells yet, or add a
photo of your own. What we can find out about it, we fill in.**

Roadmap step 1 ("anything goes in") in [../strategy.md](../strategy.md). Before this, a list held
catalogue products, and a hand-typed item was a title, an optional bare link and an optional price,
with no picture, on purpose (see *What this replaced*).

## The flow

1. In the add panel (`Components/AddProduct.tsx`) a pasted `https://` link is not searched as text.
   `GET /list-search` sends it to `LinkRouter` (below) without fetching anything, so a bol, eBay or
   feed-shop link usually comes back as an ordinary result to pick.
2. What nothing recognises comes back as `link`, and the panel saves it at once, with no card
   asking first (owner's call, 2026-09-26: "add this link?" had only one sensible answer). When the
   link also matched something, the matches are shown with "add the link itself" beneath them. The
   save is `ItemSaver::saveManual()` with the link and no title. The item is called after the shop's host
   ("small-shop.example") and marked `link_status = pending`.
3. `ReadItemLink` runs in the queue after the save commits. It asks `LinkRouter` again (this time
   connectors may be called), and only for an unknown shop reads the page (`PageReader`).
4. The result lands **on the same row** (`ItemLinker`), so a claim or a vote already made on it
   stays attached:
   - a product we hold → the item becomes a catalogue item (`link_status = linked`), exactly as if
     it had been picked from search: offers, current price, comparison;
   - otherwise the page's title (only if the person did not type one), price (only if they typed
     none, and only in the market's currency) and picture fill the gaps (`read`);
   - a source we must not read and could not place → nothing changes (`skipped`);
   - an error → nothing changes (`failed`).
5. `Lists/Show` asks for the items again every 2 s while any row is pending, for at most 30 s.

Typing a new link into a hand-written item (`PATCH /list-items/{id}`) runs the same lookup. A
visitor's suggestion on a shared list (`SuggestionController`) goes through the same `saveManual`.

## Our data and connectors first, the page last

The owner's rule (2026-09-26): **a link to a site we hold in the feed database or reach through an
API connector is resolved through that, never by fetching and parsing the page.** Our data is
better, it is what the programmes allow, and it costs the shop nothing. `LinkRouter` in order:

| Link | Resolved by | Fetched? |
|---|---|---|
| Amazon (`amazon.*`, `amzn.to`) | the ASIN in the URL → `amazon_products.identity_key` → the group in this market | **never**, not even to expand a short link |
| bol (`/p/{slug}/{id}/`) | `products` (source bol, that id); else `BolPageImport` with the slug as title, the same path the editors' extension uses | never |
| eBay (`/itm/…/{id}`) | `products`; else the connector's `fetchById`, stored and grouped like a live search result | never |
| a feed merchant (`merchants.domain`) | the product whose `merchant_deep_link` has the same host and path (tracking parameters ignored) | only if the product is not in the feed |
| anything else | `PageReader` | yes |

An Amazon link we cannot place stays a plain link with the title the person typed. Nothing from
Amazon is stored (invariant 6; see [amazon-compliance.md](amazon-compliance.md)).

## Reading a page safely

`SafeFetch` is the only way the server requests a URL a visitor gave it. Each rule closes a way round
the one before:

- `https:` on port 443 only; no credentials in the URL (`https://shop@10.0.0.1/`).
- Every address the name resolves to must be public: not private, loopback, link-local (which covers
  the cloud metadata address), carrier-grade NAT, or an IPv4 address written as IPv6.
- The connection goes to **the address we checked** (`CURLOPT_RESOLVE`), so a second DNS answer
  cannot point it at `127.0.0.1` (DNS rebinding).
- Redirects are followed by hand, at most three, each hop checked again.
- 5 s, 2 MB for a page, 8 MB for a picture; HTML only for a page, JPEG/PNG/WebP/GIF for a picture.
- 20 requests per shop per minute across everyone; one read is cached for 7 days (a refusal for one
  day), so a popular link costs the shop one request a week.
- We say who we are, in the form every well-behaved crawler uses: `Mozilla/5.0 (compatible;
  GiftCovesBot/1.0; +https://giftcoves.com)`. The bare `GiftCovesBot/1.0 (+url)` was refused
  outright by big shops' bot protection; the same name in the conventional form gets through at
  least some of the time. We never pretend to be a browser: that is blocked anyway, and it would be
  a lie.

### When a shop refuses (2026-09-26)

De Bijenkorf's bot protection refuses about half of all requests at random (measured: the same
request answered 403, 200, 403, 200), and Coolblue refuses too. So:

- A refusal that may pass (403, 408, 425, 429, 5xx, a timeout) is **not remembered**, and the job
  tries again after 30 s and then 60 s (three tries in all). A refusal that will not change (404,
  a private address, a file that is not a page) is remembered for a day, as before.
- If every try is refused, the item keeps what the person typed. If its title is still the shop's
  host, it gets the product's name **from the link itself** (`SlugTitle`):
  `…/bialetti-moka-express-percolator-6-kops-8834090013-…` becomes "Bialetti moka express
  percolator 6 kops". Codes and ids are dropped; a link that carries no words gives nothing. The
  picture and the price are only on the page, so they stay empty.

`ProductPageParser` reads JSON-LD `Product` first (what shops publish for Google), then Open Graph,
then `<title>`. No AI, and no guessing a price out of prose. Prices go to cents without float
arithmetic (invariant 7). Measured on a real Shopify page on 2026-09-26: title, brand, image, price,
currency and stock all read.

## Pictures are ours, never hot-linked

A shared list is opened by other people. An `<img>` pointing at a host the owner (or a pasted page)
chose makes every one of those browsers report who opened the list and when: a tracking pixel, on
the one kind of page where the owner is not supposed to learn about activity. That is why a manual
item had no picture at all until now.

`ImageStore` copies the picture instead: decoded and re-encoded as WebP (which drops every byte of
EXIF, including the GPS position a phone writes into a photo), longest side 1600 px, a 40-megapixel
ceiling before decoding (decompression bombs). Stored on the `media` disk and served by
`MediaController` at `/media/items/{uuid}.webp`, always as `image/webp` with `nosniff`, cached for a
year (a file is never rewritten; a new picture gets a new name).

**In production the disk is the `media_data` Docker volume**, on `app`, `queue` and `scheduler`
(the queue writes, the app serves). The container's own disk is wiped by every deploy. See
[../deployment.md](../deployment.md#services).

## Your own photo

`POST /list-items/{id}/photo` on a hand-written item only: a catalogue item shows its product's
picture. Owner or editor. `DELETE` removes it. A photo is the person's data and goes with the item:
`WishlistItem::booted()` deletes it on an item delete, and `bc:prune-personal-data` sweeps pictures
no item refers to (a list or an account deleted as a whole removes its items by cascade, where no
model event fires), with a day's grace.

## A barcode nobody sells yet

A scan that finds nothing leaves the digits in the add panel; choosing "write it in yourself" then
keeps them as `wishlist_items.gtin` (normalised by `Gtin::normalise`; an invalid code is dropped,
the item still saved). `LinkBarcodeItems` runs half an hour after each grouping run and turns such
an item into the product once a shop **in the list's market** sells it (invariant 2).

## What a read does not do (yet)

A page read fills **the item**. It does not write a catalogue offer (`products`), so a pasted shop
does not appear in search or in offer comparison. On purpose, for now: a price read once from a page
is not a price we can keep right, and "cheapest offer" has to be exactly right. A `Source::Web`
offer that joins groups but stays out of the price aggregates is the next piece; roadmap plan 1 has
the design, and the matching step (products that arrived separately) has to land first.

## What this replaced

`ItemSaver::saveManual()` used to say "we never make a request to the link", for two reasons that
were right: a server that fetches what it is handed is an SSRF probe, and a remote image is a
tracking pixel. Both reasons still stand. The rules above answer them instead of avoiding the link;
do not "fix" the fetch back out.

## Tests

`SafeFetchTest` (every refusal above, redirects, caps), `ProductPageParserTest` (storefront shapes,
prices), `PastedLinkTest` (the flow; Amazon, bol and feed links never fetched), `ItemPhotoTest`
(EXIF gone, replace, remove, sweep, serving), `LinkBarcodeItemsTest`.
