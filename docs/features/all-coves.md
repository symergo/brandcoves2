# All Coves

`/{market}/coves` — every Cove a market has published, in one page, grouped by the shape it takes.

Added 2026-08-29.

## The gap

A Cove has three shapes and each had an index of its own:

| Kind | Index | What it is |
|---|---|---|
| `daily` | `/daily` | One edition every morning, addressed by date |
| `persona` | `/gift-ideas` | A shelf built around a person, permanent |
| `guide`, `seasonal`, `advice` | `/guides` | Buying advice and guides — "Shop Smarter" |
| `shop` | `/shops` | What a shop is like to buy from ([shop-coves.md](shop-coves.md)) |

Nothing held all of them. The word the site is named after pointed at four different rooms — three
archives and a hub explaining three of them — and a reader who had worked out that "Cove" is one
thing with several shapes had no page that showed the shape of the whole thing.

`/gift-ideas` made it worse by being in no menu at all. It shipped, it was in the sitemap, and it was
reachable from no page on the site.

## Bands, not a stream

A market publishes an edition every morning and a persona every few weeks. Anything sorted purely by
date is therefore the daily column with occasional strangers in it — the exact opposite of an
overview, and the reader would have to scroll past a month of editions to discover that personas
exist at all.

So: one band per kind, in the order the Discover menu lists them, each capped and each ending in a
link to the index that owns it.

**Two bands are not Coves at all.** Brand Coves lists `brand_stats` and Shop Coves falls back to
listing merchants — because the header calls those sections Coves, and an overview that silently
omitted two of the six entries above it would be an overview of something else. The shop band
*prefers* the writing: once a market has Shop Coves it lists those, and the directory of company
names is the fallback for a market where none has been written yet.

Brands come from `brand_stats` rather than a `distinct brand` over the catalogue, for the reason
that table exists: a brand's identity is its slug, feeds disagree about punctuation, and
"Audio-Technica" and "Audio Technica" are one brand with two spellings.

**Capped, because the value here is range.** Twelve per band (eight editions — they arrive daily and
would otherwise be the page), matching `DiscoverCoveController::COVES` and for the reason stated
there: more than a taste, fewer than an archive. Sixty of each would be three archives stapled
together, and this page would be a fourth index competing with three that already work.

**An empty kind drops its band** rather than heading nothing. The same rule the Discover hub applies
to today's edition: an empty shelf is worse than no shelf, and a market that has published no
personas yet should not be told it has a persona section.

**Editions link by slug, not by date.** `CoveKind::path()` takes "whatever addresses it" and its
docblock says a `Y-m-d` for a Daily — but `/daily/{date}` 301s onto `/daily/{slug}`, so linking by
date would send every click on this page through a redirect. The cards under `/daily` already
links by slug.

**A persona carries no date.** On purpose: it never stops being current, which is why it has no
`drop_date` in the first place, and printing its publication date would invite a reader to treat an
old one as stale.

## Why `/coves`

`/coves/subscribe`, `/coves/confirm/{token}` and `/coves/unsubscribe/{token}` already live under this
prefix — which is precisely why personas are at `/gift-ideas` and not here. This route is safe beside
them because it is the literal segment and **not** a `{slug}` catch-all. Adding one later would
shadow all three the first time somebody named a Cove "subscribe".

## The homepage's "All Coves" now means it

`home.coves_all` reads "All Coves" and pointed at `/guides`, so the front page promised the whole
shelf and delivered a third of it. It points here now. Two links reading the same words and landing
in different places is the drift this codebase keeps writing about.

## Sitemap

Listed at priority 0.5 — lower than any index it links to, because it holds no text of its own and a
crawler that finds the archives through it has found the better page. It is listed for the internal
links: it is the only node connecting the daily column, the persona shelf and the article archive to
each other.

## Files

- `app/Http/Controllers/CovesController.php`
- `app/Models/BrandStat.php`, `app/Models/Merchant.php` — the two bands that are not `DailyPickSet`s
- `resources/js/Pages/Coves/Index.tsx`
- `routes/web.php` — `GET /{market}/coves`, name `coves`
- `app/Http/Controllers/SitemapController.php`
- `lang/*/site.php` — `coves`, `nav.smart`, `nav.gift_coves`, `nav.all_coves`, `nav.hint_*`
- `tests/Feature/AllCovesTest.php`

## See also

- [navigation.md](navigation.md#the-menu-names-the-cove-types-2026-08-29) — the Discover menu that
  leads here, and why it now names kinds rather than surfaces
- [cove-curation.md](cove-curation.md) — where a Cove comes from
- [daily-cove.md](daily-cove.md) — the edition archive this page samples
