---
name: Image proxy (resized WebP product pictures)
area: Frontend / Catalogue / Operations
status: Active — search cards, brand page cards and the product page's main picture; switch off with IMAGE_PROXY_ENABLED=false
date_added: 2026-09-27
---

# Image proxy

Product pictures used to be hot-linked from the shops' own servers, at whatever size the feed
carried. The product page drew bol's 250×200 thumbnail 512 pixels wide, and a search card on a
phone downloaded the same JPEG, or Coolblue's 174 KB PNG, whatever the card's width. There was no
`srcset` and no WebP.

`/img/{width}/{signature}/{source}` is our own address for such a picture. The server fetches it
once, shrinks it to the width asked for, encodes it as WebP, keeps the result on disk and serves it
with a year's cache. The page offers the browser several widths (`srcset`), and the browser picks
the one that fits the screen.

## What the page gets

The server adds `imageToken` next to `image` on three props:

- search result cards (`SearchController::card`)
- brand page cards (`BrandController::card`)
- the product page's `product` prop (`ProductController`)

The token is `{signature}/{base64url of the source URL}`. It is null when the proxy is off, the URL
is not `https:`, its host is not on the list, or it is Amazon's. In the browser,
`resources/js/imageUrl.ts` turns the token into `/img/{width}/{token}` per width.
`pictureAttributes()` gives `src`, `srcSet` and `sizes`, or the plain `src` when there is no token.

- **Why the server signs and the browser only picks the width.** Only the server holds the key, so
  the browser cannot build an address for a picture the server did not hand out. The width is not
  signed, so one token serves every width in the srcset. Any width not on the list is a 404.
- **Why a second prop and not a rewritten `image`.** `image` goes elsewhere too: JSON-LD, recently
  viewed (stored in the browser), the save-to-list flow. Those want the shop's URL. With the proxy
  off, `imageToken` is null and every page is exactly what it was before.
- **The fallback is two-layered.**
  1. If the proxy cannot make a copy (the shop answers 404, a timeout, not a picture, the visitor is
     over the fetch limit), it answers with a 302 to the original. The browser still gets the
     picture, from the shop, as before. That redirect is safe because the URL is signed and
     checked: it is not an open redirect.
  2. If `/img/...` itself fails (an `onError`), the card or page switches to the shop's URL. A
     second failure shows the placeholder, as before.

Widths and `sizes`:

| Where | srcset | sizes | 1x phone | 2x desktop |
|---|---|---|---|---|
| Product card | 160, 320, 480 | `(min-width: 1024px) 240px, (min-width: 640px) 33vw, 50vw` | 160 or 320 | 480 |
| Product page | 320, 480, 640, 960 | `(min-width: 1024px) 512px, calc(100vw - 6rem)` | 320 or 480 | 960 |

## Never an open proxy

A route that fetches whatever it is given is a free image host for anyone, and a way to make our
server request things. Each rule below closes one way in (`App\Services\Images\ImageProxy`):

- **HMAC signature** over the source URL. The key is derived from `APP_KEY`
  (`hash_hmac('sha256', 'giftcoves-image-proxy-v1', APP_KEY)`), so this signature cannot be replayed
  as anything else the framework signs. Rotating `APP_KEY` invalidates every token. That is
  harmless: the next page view carries new ones, and the copies on disk are keyed by source URL,
  not by token.
- **Host allowlist**, `giftcoves.image_proxy.hosts`, matched as a host or any subdomain of it. It is
  checked when signing and again when serving, so taking a host off the list works at once, even for
  addresses already handed out. The defaults are the image servers in our feeds: `media.s-bol.com`,
  `i.ebayimg.com`, `img.tradedoubler.com`, `images.awin.com`, `productserve.com` (Awin's resizer)
  and `bynder.com` (Coolblue). `IMAGE_PROXY_HOSTS=a.example,b.example` adds more without a deploy.
  `php artisan bc:image-hosts` lists the hosts the catalogue actually holds, with counts, and says
  which are covered.
- **`https:` only**, no credentials, no port. The fetch goes through `SafeFetch`: every resolved
  address must be public, the connection is pinned to the checked address, at most 3 redirects, each
  re-checked, a 5 MB cap, and a 4 s timeout (a browser is waiting).
- **Never Amazon** (invariant 6). Any host with "amazon" in one of its labels (`amazon.nl`,
  `m.media-amazon.com`, `images-eu.ssl-images-amazon.com`) is refused when signing, when serving,
  and at the end of a redirect chain, whatever the host list says. A false refusal costs one picture
  its WebP copy. A false pass would copy Amazon imagery onto our disk.
- **Re-encoded, always.** The bytes go through `ImageStore::webp()`, the same decoder used for
  people's own photos. It checks the type (JPEG, PNG, WebP or GIF), refuses a decompression bomb
  (40 megapixels), drops all metadata, and serves only `image/webp` with `nosniff`. Something that is
  not a picture never reaches a browser from our origin.
- **Rate limits on making copies, not on serving them.** 120 new copies a minute per visitor and
  1200 site-wide (`fetches_per_minute`, `fetches_per_minute_total`). Over either limit, the visitor
  is redirected to the original. A stored copy costs no fetch and no limiter tick.
- **A failed fetch is remembered for an hour**, so a dead URL is not asked for on every page view.
- **Stateless route.** It sits in the same group as `/media/items`, without session, CSRF, queued
  cookies, anonymous identity or Inertia. A page draws dozens of these, and each one must not be a
  Redis session write.

## Sizes: shrink, never enlarge

A copy is scaled so that its shorter side is at least the slot width, and its longer side is at most
twice the slot width. That is what fills a square card with `object-cover` without the browser
enlarging it again, and it stops a panorama from growing wide. A source smaller than the slot is
re-encoded at its own size, never blown up: enlarging only makes the file bigger while the picture
stays just as soft.

### Larger sources where the shop's server offers them

`App\Services\Images\SourceVariants` asks for a larger rendition first when the URL shape allows it,
then falls back to the original URL:

- **eBay** `s-l{N}`: the smallest of eBay's fixed sizes that covers the slot.
- **Bynder** (Coolblue) `io=transform:fit,height:H,width:W`: raised to the slot width, at most 1600.
- **Awin productserve** `?w=..&h=..`: raised to the slot width, at most 1000.

**bol has no rule, and that was measured.** A bol URL looks sized
(`media.s-bol.com/{id}/{hash}/250x200.jpg`), but on 2026-09-27, replacing the size with 550x440,
1200x960, 500x400 or 124x99, with or without the hash segment, gave a 404 every time. The hash
names that one rendition. So the product page's main picture for a bol product is still 250 pixels
wide: smaller (WebP), but not sharper. See "Decisions for the owner".

## Measured (2026-09-27, real feed pictures, the encoder as shipped)

| Picture | Before (hot-linked) | After, per width |
|---|---|---|
| bol thumbnail `…/250x200.jpg` | 20,676 B JPEG, 250×200 | 160: 9,396 B (200×160); 320–960: 14,800 B (250×200, not enlarged) |
| Coolblue Bynder PNG (800 box) | 174,077 B PNG, 416×800 | 160: 4,538 B; 320: 11,424 B; 480+: 15,502 B (416×800) |

On the product page, a bol picture goes from 20.7 KB to 14.8 KB. A Coolblue card goes from 174 KB to
11 KB (320) or 4.5 KB (160 on a 1x phone). Shops no longer see the visitor's IP address for these
pictures either, because the request is ours.

## Storage and pruning

Copies live on the `media` disk under `proxy/{width}/{aa}/{sha1}.webp`. In production that disk is
the persistent `media_data` volume, so a deploy does not empty it. `items/` beside it holds people's
own photos and is never touched from here. A copy is written under a temporary name and then
renamed, so two visitors asking for the same new picture never serve each other half a file.

`bc:prune-image-cache` (nightly, 03:35) deletes copies older than `keep_days` (30), plus leftover
`.tmp` files. The next view makes the copy again. This is how a picture that a shop replaced behind
the same URL eventually shows, and it is the ceiling on disk use. `--dry-run` counts without
deleting.

## Switching it off

`IMAGE_PROXY_ENABLED=false` on the app, then restart. Every page hands out null tokens, and every
picture uses the shop's URL again. An `/img/...` address that is still in a browser's cache or in
a cached page is answered with a redirect to the shop's URL, and nothing is fetched or served from
disk. Nothing else changes. Stored copies stay until pruned, or until `rm -r` of `proxy/` on the
volume. Switching it back on needs nothing else.

## Risks and what to watch

- **The first view of a picture waits for the shop.** A copy that does not exist yet is fetched in
  the request (bol's CDN answers in about 50 ms; the ceiling is 4 s, then the browser is redirected
  to the original). On the product page, that is the largest element of the page. Watch the access
  log's `duration` for `/img/` misses on staging. Warming copies at ingestion would remove the wait,
  but it is not built.
- **Disk.** About 5–15 KB a copy, at most five widths per picture, pruned after 30 days of age. Check
  `du -sh` on the `media_data` volume a week after launch.
- **FrankenPHP workers.** A miss holds a PHP worker for as long as the shop takes. The 4 s timeout
  and the per-visitor limit bound this.

## Decisions for the owner

1. **Sharper bol pictures need bol's media endpoint.** The Marketing Catalog API returns one
   thumbnail with a search result. A product's media endpoint lists larger renditions. Storing the
   largest one on the offer at ingestion would make the product page sharp; the proxy would then
   shrink it for cards. That is a connector change plus an API call per product (rate-limited). Not
   done.
2. **Awin merchants' own image hosts** (`merchant_image_url`, preferred by the Awin connector) are
   many and are not on the list. Run `bc:image-hosts` on production and add the big ones with
   `IMAGE_PROXY_HOSTS`, or accept that those pictures stay hot-linked.
3. **More components.** Only search cards, brand cards and the product page's main picture use the
   proxy. Rails, Coves, lists and the gift finder still hot-link. Each is one `imageToken` on the
   server prop and one `pictureAttributes()` call in the component. List pages are the privacy
   case: a hot-linked picture there tells the shop who opened somebody's list (the reason
   `ImageStore` exists).

## Files

- `app/Services/Images/ImageProxy.php`: signing, verifying, host and Amazon rules.
- `app/Services/Images/ProxiedImages.php`: fetch, encode, store, failure memory.
- `app/Services/Images/SourceVariants.php`: larger renditions per image server.
- `app/Services/Images/ImageStore.php`: `webp()` is the shared encoder.
- `app/Http/Controllers/ImageProxyController.php`, and the route in `routes/web.php`.
- `app/Console/Commands/PruneImageCacheCommand.php`, `ImageHostsCommand.php`.
- `resources/js/imageUrl.ts`, `Components/ProductCard.tsx`, `Pages/Product.tsx`.
- `config/giftcoves.php` → `image_proxy`.
- `tests/Feature/ImageProxyTest.php`.

## Transparent pictures come out on white (2026-10-05)

Found by the owner: in the search results for AEG, half the products sat on black. Coolblue serves
its photos as PNGs with a transparent ground, and the conversion to WebP lost the transparency, so
every transparent pixel became black. Two causes, both in `ImageStore::webp()`:

- **Coolblue's PNGs are palette images**: the transparency is one palette entry, not an alpha
  channel. A palette image goes through `imagescale()` with that entry turned black. The picture is
  now made full colour with its alpha straight after decoding (`imagepalettetotruecolor`, alpha
  saved), before it is turned upright or shrunk.
- **Then it is flattened onto white** (`onWhite()`), after the shrink. Every product card is white,
  so white is what a transparent ground should become, and flattening last makes it so whatever GD
  did to the alpha on the way.

The first attempt flattened onto white but missed the palette case, and was caught by trying the real
Coolblue picture: the test now covers both kinds of PNG (`a_transparent_picture_comes_out_on_white_
not_black`, with a data provider), and fails on the old code.

**The black copies are never served again.** A stored copy is kept on disk and served with a year's
`immutable` cache, so the fix alone would change nothing for a picture already made. Two versions:

- `ProxiedImages::VERSION` (`v2`) is a folder in the stored copy's path, so the server makes a new copy
  instead of serving the black one. The old folder holds orphans that `bc:prune-image-cache` deletes
  with age.
- `IMAGE_VERSION` in `resources/js/imageUrl.ts` adds `?v=2` to the address, so a browser or
  Cloudflare holding the black copy asks again. The route ignores the query string, so older
  addresses in cached pages still work. Raise both together.
