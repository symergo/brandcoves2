# The Amazon search hand-off

One link, in the search sidebar and on every product page with a barcode: **run this same search on
Amazon**. Tagged, so the click is attributed.

- Service: [`App\Services\Search\AmazonSearchLink`](../../app/Services/Search/AmazonSearchLink.php)
- Component: [`AmazonSearchCta.tsx`](../../resources/js/Components/AmazonSearchCta.tsx)
- Config: `giftcoves.amazon_search.markets` in [config/giftcoves.php](../../config/giftcoves.php)
- Tests: [`AmazonSearchLinkTest`](../../tests/Unit/AmazonSearchLinkTest.php),
  [`AmazonSearchCtaTest`](../../tests/Feature/AmazonSearchCtaTest.php)

## Why link to a shop we do not carry

Because the shopper opens that tab anyway. A results page with four offers has not answered *"am I
sure this is the best price"* — Amazon is the check they run next, and until now they ran it from
their own address bar, retyping the term, on a visit we neither saw nor earned from. The link turns
an invisible departure into an attributed one and carries the query they already typed.

It is also the only Amazon surface that does not run into invariant 6. That invariant forbids
*mirroring* — storing title, price, image or availability. A search URL holds none of that, and we
never request it, so nothing is fetched, parsed or stored. The whole feature is one anchor.

## The tags, and why the storefronts differ from `AmazonLocale`

| Market | Storefront | Tag | Env override |
|---|---|---|---|
| `nl-nl` | `www.amazon.nl` | `giftcoves-21` | `AMAZON_TAG_NL` |
| `be-nl` | `www.amazon.com.be` | `giftcoves05-21` | `AMAZON_TAG_BE` |
| `be-fr` | `www.amazon.com.be` | `giftcoves05-21` | `AMAZON_TAG_BE` |
| `en`, `es` | — | — | no link |

`AmazonLocale::primaryFor()` sends Belgian visitors to **`amazon.nl`**, because it is the deeper
catalogue and a Belgian shopper is usually better served by it. This table sends them to
**`amazon.com.be`** instead, and the disagreement is deliberate: an Associates tag is issued per
marketplace. A `.be` tag on an `amazon.nl` URL is not an error Amazon reports — the page loads, the
visitor buys, and the commission goes nowhere. Attribution decides the host here; catalogue depth
decides it there.

`be-fr` shares the `.com.be` storefront with `be-nl` because Amazon serves that marketplace in both
languages from one host, and there is no separate French-Belgian tag to route to.

**A market with no tag gets no link.** `en` and `es` are absent from the config rather than empty.
An untagged Amazon link is the failure mode that looks exactly like a working one, so a visible
absence is the safer default — and adding a market later is a config line, not a code change.

## Why the URL is built on the server

The tag is the whole point of the link, and a URL assembled in the browser would be missing it with
nothing in the rendered page to show that. `AmazonSearchCta` therefore receives a finished `{host,
url}` or `null`, and never composes one. `null` is the normal answer in two of five markets.

## `/s?k=`, not `?s=`

`/s` is the search **path**; `k` is the keyword **parameter**. They read as one thing in a URL —
`amazon.nl/s?k=koptelefoon` — and they are not. As a query parameter, `s` is Amazon's *sort* key
(`s=price-asc-rank`), so `/s?s=koptelefoon` sorts by a value that does not exist, over no query, and
answers with an empty page. `k` replaced the older `field-keywords`, which still redirects.

The term goes through `http_build_query` with RFC3986 encoding. A raw `&` in a search term — "Procter
& Gamble" — would otherwise end the `k` parameter and leave `tag` as a value on a key Amazon ignores:
a working page, zero attribution.

## The product page searches the barcode, not the title

The EAN is the only string that means the *same product* on the other side. A title search returns
the accessories, the refill and the previous generation; a barcode either finds this exact item or
finds nothing, and finding nothing is a truthful answer.

So the link is absent on a group whose identity is a folded brand-and-title key rather than a
barcode — the same condition that hides the barcode line above it, and a large share of the
catalogue. A link that quietly searches for something else is worse than no link.

## The mark, and why it is Amazon's favicon

The CTA carries the storefront's own favicon — `https://www.amazon.nl/favicon.ico` — which is the
same idiom offer rows already use for every shop (`Merchant::faviconUrl()`). Amazon's file, served by
Amazon: nothing of theirs is copied into our `public/`, which keeps this well clear of reproducing a
trademark, and there is no asset of ours to keep in sync when they change it.

A favicon URL is a convention rather than a guarantee, so the `<img>` hides itself on error instead
of reserving a placeholder box. A missing icon costs 24px of alignment; a broken-image glyph in the
middle of a button costs the click.

## Placement

- **Search:** the foot of the filter rail. It is an alternative to the whole page, not to any
  product on it; in the grid it would compete with the offers we do carry, which are the ones a
  click here should be worth *less* than. The by-store view has no rail, so it carries no link.
- **Brand:** the same place in the same rail, searching the brand plus whatever term chips are
  narrowing the page — a visitor who has clicked down to "Sony koptelefoon" hands that across rather
  than starting again at "Sony".
- **Product:** directly under the barcode it searches for, below the buy buttons.

**On a page that found nothing**, search and brand both move it into the empty state, in the middle
of the screen, and drop the copy in the rail — two identical accent buttons on one view is one of
them being ignored. A dead end is where this link is worth most: we found nothing, the shopper's
question is still open, and the next thing they do is try the shop we do not carry. Shown even when
the emptiness is our own filters' doing, where "clear the filters" is the better answer and sits
above it.

Both render `rel="sponsored noopener nofollow"` in a new tab, the same as every other outbound
affiliate link on the site.

**It is drawn as loudly as our own buttons** — accent fill, the shop's mark, an arrow — because a
quiet version does not get the click, and the click is the whole point. As a bordered note in the
rail it read as a footnote *about* Amazon rather than a way to go there, and the shopper still left
through their address bar: the outcome the link exists to replace. The cost is real and accepted, it
competes with our own primary actions on the same screen. It is placed where that competition does
least damage rather than toned down.

## Not gated on `AMAZON_ENABLED`

That flag governs the PA-API connector, which needs credentials and is still Phase 8. This needs
neither, so tying it to that flag would leave a shipped feature switched off waiting on an unrelated
one. The config lives at `giftcoves.amazon_search`, outside `connectors.amazon`, to make that
independence visible rather than a thing you have to read the code to discover.
