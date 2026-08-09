# Pasting an Amazon link into the search box

Someone standing on an Amazon product page, wondering who else sells the thing, pastes the URL into
whichever box is nearest. That box is the search field, so the search field understands URLs.

**One input, not two.** A separate "paste a link here" mode would only work for people who already
knew it existed, and the paste happens before anyone goes looking for a feature. `AmazonLink::parse()`
sits in front of every search and returns null for anything that is not an Amazon URL, which is what
makes it safe to put there: an ordinary query takes exactly the path it always did.

## What a URL gives us

Two things, and the less obvious one is the one that works.

The **ASIN** identifies the product exactly. `amazon_products` is the decision store the compliance
rule requires (ASIN, classification, resolved identity, no mirrored title or price — see
[amazon-compliance.md](amazon-compliance.md)), and its `identity_key` is the same key `product_groups`
is unique on per market. A classified ASIN therefore points straight at the group the other shops'
offers hang off, and the paste becomes a redirect to that product page. That table is empty until the
connector runs, so this path is correct and currently silent.

The **slug** in front of `/dp/` is the product title with dashes in it —
`Sony-WH-1000XM5-Draadloze-Koptelefoon`. Unglamorous, always present on a desktop link, and the thing
that actually answers the question today: those words search perfectly well against the shops we do
carry. So the ASIN is kept for later and the slug is searched now, with `amazon_products
.classified_title` preferred over it when we have one, because a classified title is the product's
real name rather than a marketing string with the colour and pack size welded on.

Link shapes handled: `/dp/`, `/gp/product/`, `/gp/aw/d/`, `/gp/offer-listing/`, `/product/`,
`/exec/obidos/ASIN/`, `/d/`, and `?asin=`. All of them still resolve on Amazon and all of them still
get pasted, because a link lives as long as the message it was sent in.

## Two refusals

**We never fetch the URL.** Not to expand a shortlink, not to read a title. A visitor-supplied URL
that the server requests is server-side request forgery with a search box in front of it, and even
against a host allowlist it would put Amazon's latency inside our request handler. `amzn.to` and
`amzn.eu` links are recognised and reported as unresolvable, with a note asking for the full address.

**We do not guess.** A bare `/dp/B09XS7JWHH` has no title in it, so the page says we could not read
the product rather than running a query built from routing tokens. The same rule sets the floor at
two words: one word off an Amazon URL is nearly always a leftover segment, and a page of unrelated
results looks exactly like having understood the link.

Host matching is done on the parsed host and anchored at both ends, so
`https://evil.test/www.amazon.nl/dp/…` and `https://amazon.nl.evil.test/dp/…` are not Amazon links.
A false positive here would hijack a real search.

## What the visitor is told

The query that ran is not the text that was pasted, so the page says which is which, directly under
the box. Without it a grid of headphones under a URL gives no way to tell whether we found *that*
product or something sharing a word with it. Three states: searched (with the terms), unreadable, and
shortlink.

## What is recorded

An `amazon_paste` event with the market, the ASIN, and whether we could search — never the URL. A
pasted Amazon link carries `ref=` breadcrumbs and occasionally a session identifier, and none of that
belongs in a table with a 90-day life. The signal is the same one the barcode scanner produces: a
person has told us a product exists and that we could not identify it, which is a supply gap worth
counting.

## Files

- [AmazonLink.php](../../app/Services/Search/AmazonLink.php) — the parser, pure and unit-tested
- [SearchController.php](../../app/Http/Controllers/SearchController.php) — resolution and the redirect
- [AmazonLinkTest.php](../../tests/Unit/AmazonLinkTest.php) — link shapes, including the hostile ones
- [AmazonPasteSearchTest.php](../../tests/Feature/AmazonPasteSearchTest.php) — the flow end to end
