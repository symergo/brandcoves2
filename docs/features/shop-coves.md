# Shop Coves

`/{market}/shops` — the shops this market's prices are compared across, and the writing about them.
`/{market}/shops/{slug}` — one shop, described.

Added 2026-08-29.

## The gap

Every offer card on this site names the shop it came from, and nothing anywhere answered the two
questions that raises. *Which* shops are these — "we compare hundreds of shops" is a claim a visitor
cannot check, and a list they can scroll is worth more than the claim. And what is this shop like to
buy from, which is the half of a buying decision a price comparison cannot answer.

## Two halves, in that order

The page leads with the **Coves** — the writing — then the **directory**. The writing is the reason
to read the page; the directory is the reason to scroll it.

The directory is new arrivals, then the whole thing A–Z with the new ones **repeated inside it**.
Repetition is the point: the spotlight is a band and not a filter, and a shop missing from the
alphabet because it happens to be new is a shop somebody scrolling for it cannot find.

**Nothing is counted.** Same rule as the Discover hub and the front page — a page that totals the
catalogue makes a claim a visitor cannot check, and it is the number most likely to be wrong. It is
also what keeps the page cheap: counting products per shop is a scan per shop over the largest table
in the database.

**When every shop is new, nothing is featured.** Not hypothetical — measured on the development
database, where all six shops were inside the thirty-day window, so the spotlight reprinted the page
below it under a heading promising something had changed. "New" is a comparison, and a comparison
against nothing says nothing. The badge on each card stays; only the band is suppressed.

## Membership: the catalogue, not the feeds

A shop is in a market when it has **active offers there**, or is a **live source** whose connector
supports the market. That is one `EXISTS` against `products.merchant_id`, which is indexed.

It was written against `feeds` first, on the reasoning that the integration is the truth and the
catalogue merely reflects it. That was wrong twice over:

- **`feeds.merchant_id` is null on every row in the database.** Nothing in ingestion sets it. The
  join matched nothing, and `/shops` listed bol.com and nothing else — no error, no exception, just
  a page that was almost empty. The null FK is a real gap and is left as one: backfilling it means
  matching feeds to merchants by label, which is a guess, and this page does not need it.
- A **live source has no feed at all**, so half the answer had to come from the connector registry
  regardless. Live sources are listed whether or not they have rows yet, because their offers are
  fetched per request rather than ingested — a market can compare bol prices while holding almost
  nothing of bol's in `products`, and invariant 6 makes that permanently true of Amazon.

`ConnectorRegistry::liveSourcesFor()` is new and deliberately unlike `liveFor()`: that one drops a
source backing off after a 429 so a *request* degrades, and a shop directory that loses bol because
bol is briefly refusing would tell a visitor we do not carry it.

## `CoveKind::Shop`, the sixth kind

A Shop Cove is an article. It is planned, curated, written and published like any other Cove, and it
is a database row a person can open and rewrite.

**It is prose, and it is not in `/guides`.** That distinction is the whole risk in this feature, and
it is why one method became two:

| | Question | Shop answers |
|---|---|---|
| `isArticle()` | Does it live in the `/guides` URL space? | **false** |
| `expectsShortlist()` | Does the page render a ranked list under the prose? | **false** |

They used to be the same question. Answering `isArticle()` true would sweep Shop Coves into the
guides index, the guides sitemap and the guides hreflang pairing — three places they do not belong
and none of which errors. `expectsShortlist()` replaced the `=== CoveKind::Advice` checks in
`GuideController`, which were spelling the same idea one kind at a time.

`aiFeature()` stopped delegating to `isArticle()` for the same reason: a Shop Cove is prose written
by the same prompt shape against the same budget, and billing it to `daily_picks` would spend the
daily column's cap on something that is not the column.

**The page is `GuideController`.** `/shops/{slug}` routes to `GuideController::shop()`, which is
`show()` with a different kind filter. The allowlist, the prose resolution, the FAQ, the preview gate
and the structured data are all properties of *an article*; a second copy would drift within a month.

**hreflang gets its own method** rather than a widened `guide()`. Shop Cove slugs come from the
shop's domain, so the same shop keeps the same slug in every market it trades in — which is what
makes them pairable at all — but a `/guides/{slug}` sharing that slug is a different page, exactly
the reason personas were excluded from `guide()` in the first place.

## The writing

`resources/content/shop-coves.php` holds the shipped text, keyed on `merchants.domain` and then by
language. Not on the name (an editor tidies "Coolblue BE" to "Coolblue") and not on `external_id`
(an Awin advertiser number that changes on re-onboarding).

`php artisan bc:seed-shop-coves` publishes it into the markets that carry each shop — the same
membership question `/shops` asks, so a Cove and its directory entry appear and disappear together.
Idempotent, and it **never overwrites a person**: a row is refreshed only while its
`editorial_source` is still `seed`, and `--replace` asks before overriding that. `published_at` is
stamped once and never refreshed, or every re-run would reshuffle the shelf.

A shop with no text in the market's language is skipped rather than published in the wrong one. An
untranslated Cove is worse than an absent one.

**Slugs replace the dot rather than dropping it.** `Str::slug('bol.com')` is `bolcom`, which reads as
a typo in a URL and in a `[[guide:...]]` token. `bol-com`, `coolblue-be`, `shop-action-com`.

### What may and may not be said

The shipped text follows the same rules as `Defaults::SHOP_SYSTEM`, the prompt an AI-written one is
held to — a hand-written Cove breaking rules the generated ones keep is how a section stops being
coherent. No delivery times, return windows, shipping fees, minimum orders or subscription prices:
they differ per market, change without notice, and a reader who acts on a wrong one is out of pocket.
No "cheapest", "best" or "fastest": the comparison on the product page answers that, and the answer
changes per product. No invented history.

What is left is what we can stand behind — what they sell, who they suit, and what to check on the
shop's own page. We earn a commission on what people buy through us, which is exactly why a piece
that finds nothing to qualify would not be worth publishing.

## Files

- `app/Http/Controllers/ShopsController.php` — the directory and the band above it
- `app/Http/Controllers/GuideController.php` — `shop()`, and `render()` shared with `show()`
- `app/Enums/CoveKind.php` — `Shop`, `expectsShortlist()`
- `app/Models/DailyPickSet.php` — `scopeShops()`
- `app/Services/Connectors/ConnectorRegistry.php` — `liveSourcesFor()`
- `app/Services/Ai/Prompts/Defaults.php` — `SHOP_SYSTEM`, `SHOP_PROMPT`
- `app/Console/Commands/SeedShopCovesCommand.php`, `resources/content/shop-coves.php`
- `resources/js/Pages/Shops/Index.tsx`
- `database/migrations/2026_08_31_000100_a_shop_can_have_a_cove_written_about_it.php`
- `app/Services/Seo/Alternates.php`, `app/Http/Controllers/SitemapController.php`
- `tests/Feature/ShopCovesTest.php`, `tests/Feature/ShopCoveArticleTest.php`

## Withheld from the header, 2026-08-29

`/shops` is live, indexed and linked from All Coves, but its Discover-menu entry is deliberately not
in place yet. See
[navigation.md](navigation.md#brand-coves-and-shop-coves-are-built-and-withheld-2026-08-29) for
exactly what restoring it involves — nothing was deleted.

## Still outstanding

- **No shop page of its own.** A shop card links into `/search?merchant[]=<id>`, which works — with
  no term the stored query still runs — but a real `/shop/{slug}` mirroring `/brand/{slug}` would be
  better, and needs a slug column `merchants` does not have.
- **`feeds.merchant_id` is never populated.** Not needed here any more, but it is a dangling FK that
  will mislead the next person who reaches for it.
- **The planner does not queue Shop Coves.** They are seeded and then editable; there is no topic
  miner proposing "write about this shop" the way `TopicPlanner` does for guides.

## See also

- [all-coves.md](all-coves.md) — the overview that lists these alongside every other kind
- [navigation.md](navigation.md#brand-coves-and-shop-coves-2026-08-29) — the menu that leads here
- [cove-curation.md](cove-curation.md) — how a Cove is planned, curated and built
