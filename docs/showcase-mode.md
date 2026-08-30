---
name: Showcase mode — presenting /en/ to Amazon as an editorial site
area: Core / Compliance
status: Archived 2026-08-30 — planned, not started, no code written
date_added: 2026-08-30
---

# Showcase mode — presenting `/en/` to Amazon as an editorial site

> **This is an archived plan, not a feature.** It is filed in `docs/` rather than `docs/features/`
> because nothing here ships: no code was written and nothing in the repo was touched. It is kept
> because the research behind it — what the `en` market actually contains, and which seams the change
> would have to go through — cost more than the writing did, and would otherwise have to be redone.
>
> Every file path, line reference and claim below was verified against the tree at commit `59f2f69`
> (branch `staging`, 2026-08-30). **Two things to re-check first if this is picked up**, because they
> are load-bearing and the tree will have moved:
>
> - that `SearchService::storedQuery()` (:72-73) and `countFacets()` (:521-522) still hand-roll the
>   `presentable()` conditions rather than calling the scope — if they now call it, §3 gets simpler;
> - that `Alternates::defaultFor()` (:398) still falls back to `reset($alternates)` — if not, the
>   `x-default` hazard in §10 may already be solved.
>
> See also [features/amazon-compliance.md](features/amazon-compliance.md), which is the doc that
> governs anything actually touching Amazon data.
>
> **Its premise moved on 2026-08-30.** bol no longer serves `en` (`Market::bolCountry()` is null for
> it) and the 3,400 Dutch-titled offers this plan was built around have been suppressed with
> `bc:withdraw-source`. So "every `en` product title is Dutch" — called the one irreducible problem
> below — is no longer true, because there are no `en` product titles at all. What that costs this
> plan is §§3-6: there is nothing left to filter prices out of. What it does not solve is the goal,
> since an empty market is not an editorial showcase either. See
> [features/market-routing.md](features/market-routing.md#en-is-published-and-has-no-live-source--2026-08-30).

## Context

GiftCoves is applying to Amazon Associates. Amazon reviews the applicant site and wants a content
property, not a bare affiliate directory. What we would show them today is the opposite: every market
is a price-comparison surface built around bol.com and eBay affiliate links, merchant logos,
"from €X · 3 offers across 2 shops" cards and a `/shops` directory.

The goal was to make the **`en` market on production** an English editorial showcase — articles,
product pictures, English product names, search and wish lists — with no prices, no shop names, no
affiliate links, no offer comparison and no product pages. Every other market unchanged.

**This is the deliberately smaller of two designs.** The thorough version scrubbed the Inertia payload
server-side and AI-rewrote the entire 3,400-group catalogue with a generated tsvector index behind it.
That was rejected as over-built. What follows filters in React and rewrites only the ~150 products that
actually appear in articles — roughly a third of the work.

### The one thing that is not reducible

Every `en` product title is **Dutch**, stored — `Market::En->bolAcceptLanguage()` returns `'nl'` on
purpose, because bol has no English catalogue. Filtering prices and links gives you an English site
showing *"Strex OBD2 Scanner - Auto Uitlezen en Storing Verwijderen - Nederlandse Taal"* under an
English headline. No filtering fixes that; it needs a `title_en` column and something to fill it. What
the smaller design changes is the **scale** — 150 titles driven off the editorial, not 3,400.

### What the reduced scrub costs, stated plainly

Prices and merchant names remain in the page-props JSON embedded in the served HTML. A human reading
the page sees a clean editorial site; someone opening view-source would find `minPrice` and a merchant
name. Google indexes rendered content, not the `data-page` attribute, so this is an inspection risk
only. The consequence for verification: **"no price is visible" cannot be asserted in a PHPUnit test** —
the server sends the value and React declines to draw it, and this repo has no JS test setup. That half
is verified by eye. Everything the server controls stays fully tested.

### And one thing to be clear about before building

`/en/` is a subtree of `giftcoves.com`, and `/be-nl/` on that same domain is a full comparison page
carrying bol and eBay affiliate links. This plan hides the market switcher on `/en/`, drops `en` from
every hreflang cluster and 404s the click-out route, so nothing on an `/en/` page points at them. It
cannot make them unfindable from the bare domain. If that property has to survive a reviewer typing
`giftcoves.com`, a separate instance on its own domain is the only thing that delivers it — and because
the switch is env-driven and per-market, moving later is a new Coolify app, not a rewrite.

Related, out of scope, worth knowing: `Market::default()` is `En`, so `/` sends every visitor with an
unrecognised `Accept-Language` into the showcase market.

---

## 1. The switch

`config/giftcoves.php`, new top-level key modelled on `legacy_hosts` (:39-42):

```php
'showcase_markets' => array_values(array_filter(array_map(
    trim(...),
    explode(',', (string) env('SHOWCASE_MARKETS', '')),
))),
```

Config rather than a `Market::isPublished()`-style match arm, and the docblock should say why:
`isPublished()` is a permanent product fact; showcase is an *environment* fact that must reverse without
a deploy, and staging must be able to disagree with production.

`app/Enums/Market.php`, beside `isPublished()`:
- `isShowcase(): bool`
- `showcaseKeys(): array` — filtered through `Market::tryFrom()`, so a typo (`SHOWCASE_MARKETS=en-gb`)
  cannot put a bogus value into a SQL `whereNotIn`
- `isIndexableAlternate(): bool` — `isPublished() && ! isShowcase()`, for `Alternates`

**Config-contract chores.** Verified: `ConfigContractTest::settingsWithoutDefault()` only scans `env('KEY')`
with *no* fallback, so a defaulted `SHOWCASE_MARKETS` is invisible to all three assertions. Since the
failure mode is *production silently not in showcase mode while a reviewer is looking*, add a named test
beside the existing `the_second_awin_account_is_reachable()`. Plus `.env.example` and the
`docker-compose.coolify.yml` passthrough.

**To Inertia:** `'showcase' => $market->isShowcase()` in the existing `market` block of
`HandleInertiaRequests` (~:72), and `showcase: boolean` on `CurrentMarket` in `resources/js/types.ts`.
This is the switch the React filter reads.

## 2. English titles, scoped to the editorial

**Migration** on `product_groups`: `title_en text NULL`, `title_en_source text NULL` with a CHECK
`IN ('ai','editor')`. Provenance earns its column for one reason at this scale: you will hand-fix a bad
AI title, and a re-run must not clobber it — the same rule `SeedShopCovesCommand` enforces with
`editorial_source`. No `title_en_at`; there is no staleness refresh at 150 rows.

**`app/Jobs/WriteEnglishTitles.php`**, modelled on `app/Jobs/WidenGiftAngles.php`. The batch query is
what makes this small — *groups that appear in articles*, not the catalogue:

```
product_groups where market = :market and title_en is null and id in (
    select group_id from daily_picks join daily_pick_sets … where market = :market
    union
    select group_id from cove_plan_items join cove_plans … where market = :market
)
```

Editorial-driven, so re-running it after publishing more coves fills exactly the new ones. Batch 40 per
call; ~150 groups is four calls.

- Feature key `english_titles` in `config/giftcoves.php` under `ai.caps`, value **10** — four calls plus
  headroom for re-runs, not a backfill cap.
- Prompt as a heredoc in the job, not `PromptBank`: `PromptBank::slots()` is derived from
  `CoveKind::cases()` and shoehorning a title rewriter would widen an allowlist that exists to keep stale
  rows inert. `WidenGiftAngles` sets the in-job precedent.
- Rules: brand and model number byte-exact; translate the product noun and its qualifiers; drop retailer
  boilerplate, colour/size codes and marketing adjectives; never invent a specification; **never name a
  shop**; ≤80 characters.
- **Validate before writing** — this goes on a public page. Drop any id not in the batch; titles over 120
  or under 3 characters; anything matching a `merchants.name` (via `withoutCountrySuffix`) or a
  `Source::label()` (`bol.com`, `eBay`, `Awin`, `Amazon`); anything containing `€` or a price shape.
  Rejected rows stay null, retry next pass, log the count. Never overwrite `title_en_source = 'editor'`.
- **`AI_ENABLED=false`:** log and return, as `WidenGiftAngles` does. `title_en` stays null, §3 hides those
  groups, and the showcase market shows no products. There is deliberately no Dutch fallback — passing a
  Dutch title through as English is the single thing this feature exists to prevent. This is the one
  qualification to this codebase's "everything works with AI off" promise and it must be documented.

**`bc:write-english-titles`** `{--market=} {--batches=1} {--queue}`, inline by default like
`RefreshDiscoveryCommand`, reporting the remaining-null count. Refuses a non-showcase market without
`--force`.

## 3. Never show a Dutch title — `scopeShowcaseReady()`

`app/Models/ProductGroup.php`, ~12 lines and the safety net for the whole feature:

```php
public function scopeShowcaseReady(Builder $query): void
{
    $showcase = Market::showcaseKeys();
    if ($showcase === []) { return; }          // no clause at all — query plans unchanged
    $query->where(fn (Builder $q) => $q
        ->whereNotIn('market', $showcase)
        ->orWhereNotNull('title_en'));
}
```

Fold `->showcaseReady()` into `scopePresentable()` (:80) — one edit reaches ~20 call sites
(`SitemapController`, every `Discover\Retrievers\*`, `Cove\Selectors\*`, `EditionBuilder`,
`ProductLookup`, `DailyCoveController`, `SerendipityController`, `Alternates`). Same shape and same
reasoning as its existing `image_url IS NOT NULL` rule.

**`SearchService` bypasses `presentable()`** — verified: `storedQuery()` (:72-73) and `countFacets()`
(:521-522) hand-roll `whereNotNull('min_price')->whereNotNull('image_url')`. Both need `->showcaseReady()`
added explicitly.

## 4. English titles reach the pages — one accessor

A get-only `Attribute` on `ProductGroup::title()` returning `title_en` on a showcase market, instead of
editing ~25 payload builders. `title` stays the stored merchant title; every write, SQL predicate and
index still reads the column.

**Two things this touches that are not payload builders, both intended:**
- Scoring and dedup read `$group->title` — `Discover\Ranker::titleOverlap` (:217),
  `SerendipityEngine::lexicalRarity`/`meaningfulWords` (:79, :147), `QuizBuilder` (:149),
  `SuggestionEngine` (:268), and `GuideWriter`/`EditionBuilder` prompt assembly. On an English market
  these *should* read English. An improvement, but write it down.
- `EditionBuilder:804` slugs a pick as `Str::slug($group->title).'-'.$group->id`. English slugs on an
  English site are correct, and the `-{id}` suffix keeps every URL resolvable.
- Identity keys are built from *offer* titles in the ingestion path, not `ProductGroup->title` — confirmed
  unaffected.

**The partial-select trap must fail loud.** `->get(['id','slug','title'])` leaves `title_en` unloaded. The
accessor must **not** fall back to the Dutch string — a silently wrong language is exactly what this
feature prevents. Return null and log, so it reads as obviously broken rather than quietly wrong, and add
`title_en` to the explicit column lists that render.

## 5. The React filter

`market.showcase` from shared props is the switch.

**`resources/js/Components/ProductCard.tsx`** — the one that matters. Keep image, brand + `brandUrl`,
title, `<SaveToList compact />`. Remove:
- the `<Link>` around `{group.title}` **and** the stretched-link `<span className="absolute inset-0 z-10">`
  — leaving the span makes the whole card a dead click target;
- the entire `mt-auto pt-3` block. The price is already null-guarded, but the "N offers · across N shops"
  line is not and would render "1 offer · 1 shop";
- the out-of-stock badge and the discount badge — both are buying facts.

**`Search.tsx`** — hide the `view=store` toggle and the shop chip row from the filter rail.
**`Brand.tsx`** — hide the live-offer block (:430-530) and the `minPrice`/`merchantCount` table columns.
**`Product.tsx`, `Shops/Index.tsx`** — untouched; their routes 404 (§7). Deleting them would make the
flag irreversible.
**`SiteLayout.tsx:477`, `PageNarrative.tsx:65`** — hide `t('footer.affiliate')`; an affiliate disclosure
on a site with no affiliate links is itself a reference to other shops.
**`Daily/Edition.tsx`** — no change; the "biggest drops" column already guards on `deals.length > 0` and
the controller returns `[]` (§6).

## 6. Server changes that are still required

These are not leak-proofing — each is something that would be **visibly wrong on the page**, which React
filtering cannot fix.

| File | Change | Why it cannot be a React filter |
|---|---|---|
| `app/Services/Pages/PageCopy.php` | `forPage()`/`forRegion()` return `[]` on a showcase market | The narrative rails on Search (:115) and Brand (:177) are *rendered HTML sentences* from `BrandContext::computeFacts()` (:50-74), whose facts include `shops`, `shop` (the top merchant's name) and `low`/`high`/`percent`. A shop name arrives inside `blocks[].html`. |
| `app/Services/Search/SearchService.php` `search()` | `hasLiveTerm() && ! $market->isShowcase()` | Live connectors return unstored offers carrying merchant names that render, and `groupIncoming()` would write fresh Dutch-titled groups mid-review. |
| `app/Services/Search/SearchService.php` `countFacets()` | skip the merchant aggregate, return `merchants => []` | Drives the shop filter chips. |
| `app/Http/Controllers/SearchController.php` | `lanes => null`; drop `store` from `SearchQuery`'s accepted `view` | Store lanes are merchant logos and names. |
| `app/Http/Controllers/OgImageController.php` | `product()` → 404; `offerLine()` (:167) → null; `brand()` drops the shops count (:153) | The OG card is a PNG **we draw**, with "14 shops · from €279" painted into it. |
| `app/Http/Controllers/DailyCoveController.php` | `deals => []` (:169) | Discount percentages are comparison output. |
| `app/Services/Discover/ModeRegistry` | filter `compare` and `deals` out of `all()` | They are offer-comparison surfaces by definition; also removes them from the sitemap loop for free. |
| `app/Http/Controllers/ScanController.php` | `resolve()` lands on `/{market}/search?q={ean}`, not `/p/…` | Product pages 404. |
| `app/Support/MarketSwitcher.php` | `payload()` returns `[]` when the current market is showcase | `MarketSwitcher.tsx:66` already returns `null` on an empty list — zero React change. |

## 7. Routes

`app/Http/Middleware/BlockedOnShowcase.php` — `abort_if($market->isShowcase(), 404)`. ~15 lines. 404, never
403 and never a redirect: on this market the page does not exist, and "forbidden" would advertise that it
exists elsewhere. Aliased in `bootstrap/app.php`, applied **on the route definitions** in `routes/web.php`,
where a reader of that file sees it.

Blocked: `product` (:134), `go` (:141), `click.beacon` (:149), `shops` (:698), `shops.show` (:712),
`og.product` (:742), `alerts.store`/`alerts.destroy` (:420-423).

Not blocked, kept working: `/discover/*`, `/surprise`, `/scan`, `/gift`, `/brands`, `/brand/{slug}`.

**Inbound links.** `ProductCard` stops linking (§5); `GuideController:156`, `GiftController:263`,
`EditionPresenter` and `ProductLookup` return `null` for the product URL — and `CoveMarkup` already degrades
an unresolvable `[[product:…]]` token to plain text, which is the existing behaviour for an unpublished
guide. `/shops` is already withheld from the header nav (`SiteLayout.tsx:152`).

## 8. English search — the query branch only

No generated column, no new index. At ~150 English-titled groups a `<%` word_similarity scan is
microseconds, and `pg_trgm`'s operator works without one. One branch at the top of
`SearchService::applyTextMatch()` (:170) on a showcase market, matching `? <% title_en` or
`title_en ILIKE %term%` against `product_groups`, and `orderByRelevance()` (:234) reading
`word_similarity(?, title_en)`. The session-wide `word_similarity_threshold` set in `AppServiceProvider`
still applies, so typo tolerance is identical.

**Explicitly not done, and why:** `bc_search_vector()` is untouched. Changing it means dropping and
re-adding `products.search_vector` — an `ACCESS EXCLUSIVE` rewrite plus GIN rebuild on the largest table,
which the `2026_08_10_000500` migration's own docblock warns against — and it would be pointless, since
`products.title` is the *merchant's* title and stays Dutch. Add a `GIN (title_en gin_trgm_ops)` index only
if the English-titled set ever grows past a few thousand.

## 9. Copy

Edit the affected strings in `lang/en/site.php` directly. `Market::En` is the only market whose language is
`en`, so this is scoped to exactly the showcase market. `LocalisationTest::every_language_defines_every_key()`
uses this file as its *reference* — changing a **value** breaks nothing; adding or removing a **key** breaks
everything. So change values only.

Affected: the shop-naming prose at :105, :148-150, :183, :195, :216, :1126-1127, :1144-1145 ("Search bol,
Amazon and hundreds of shops", "That is an Amazon link"), plus `product.disclosure` (:270) and
`footer.affiliate` (:478) emptied. `search.placeholder` (:147) no longer names Amazon — it became
"Search for a gift or scan a barcode" — so it is off this list.

Reversibility here is git, not an env flip — that is the trade for skipping a translation-loader overlay,
and it should be noted wherever this ships.

**Also:** delete the `'en'` block from the `bol.com` entry in `resources/content/shop-coves.php` (:51-55) —
a whole published Cove about another shop — and guard `SeedShopCovesCommand::handle()` to skip showcase
markets so a re-seed cannot bring it back.

## 10. SEO

**`Alternates` — a showcase market must be in no cluster at all, neither member nor target.** The class's own
docblock records that Google discards an entire cluster when one declared member is missing or
non-reciprocal. If `/en/` declared the other three and they did not declare it back, the annotation is
one-sided and the three genuine translations are at risk.

- `for()` returns `[]` when the current market is showcase — a page with no alternates is not a broken
  cluster, it is a page with no translations, which is true.
- `swap()`, `daily()`, `persona()`, `guide()`, `shop()`, `product()`, `forProducts()` all filter on
  `isIndexableAlternate()`. Six sites, not one: `product()` and `forProducts()` filter on `presentable()`
  only today and would otherwise put a 404 into a `be-nl` cluster.
- **`defaultFor()` (:398) is `$alternates[Market::En->hrefLang()] ?? reset($alternates)`** — verified.
  Dropping `en` removes the site's natural `x-default` and `reset()` would silently make `be-nl` the
  x-default. Make the fallback an explicit, commented choice — a real, temporary SEO cost, and it should be
  a line of code rather than a fallthrough.

**`SitemapController`** — for a showcase market: page count `1`; omit `/shops` (:118-124), the Shop-Cove
block (:180-200) and the whole product block (:270-300) with its `forProducts()` call. Keep home, search,
search-help, daily, gift-ideas, guides, coves, brands, brand pages, legal, gift, gift-cove, discover-cove,
surprise, and the discovery modes minus `compare`/`deals`.

**robots.txt — no change, and that is the right answer.** `en` is published so it is not disallowed;
`Disallow: /*/go/` already covers a route that now 404s. Do **not** add `Disallow: /en/` — hiding the market
would make it look like a doorway page, the opposite of the argument being made.

## 11. Data runbook for `en`

Idempotent. Run against production data **before** flipping the flag.

```bash
php artisan tinker --execute="dump(App\Enums\Market::showcaseKeys());"   # prove it is off

# 1. Grouping — 3,424 products against 51 groups means this has never properly run.
php artisan tinker --execute="App\Jobs\GroupProducts::dispatchSync(App\Enums\Market::En);"

# 2. giftability -> serendipity -> brand stats -> edition. surprise_score and brand_stats are
#    both empty, so /en/surprise and every /en/brand/* 404 until this runs.
php artisan bc:refresh-discovery --market=en
php artisan bc:pull-charts --market=en

# 3. Unpublish the one existing en Cove: daily_pick_sets where market='en' and kind='shop'
#    — it is an article about buying from bol.com.

# 4. Draft and author the articles. The title rewrite is driven off these, so they come first.
php artisan bc:plan-coves --market=en --days=120
#    plus personas via PlanDrafter::fromInterests, and hand-authored guides/advice through the
#    editorial API — the `giftcoves-seed-coves` skill is the tool for that.

# 5. English names for exactly the products those articles use.
php artisan bc:write-english-titles --market=en --batches=5   # repeat until pending is zero

# 6. Only now: SHOWCASE_MARKETS=en in Coolify, redeploy.
```

**The Dutch topic leak — do not draft `en` guides from the topic queue.** All 60 `en` `guide_topics` have
Dutch names, from two sources: `config/cove_seasons.php` topic keys are Dutch words (`schoonmaken`,
`tuinieren`, `zwembad`) written verbatim for *every* market, and `TopicMiner::withChartTopics()` groups on
`chart_categories.name`, i.e. bol's Dutch category names. `TopicPlanner::draft()` (:75) writes
`Str::ucfirst($topic->topic)` straight into `cove_plans.title` and `focus_keyphrase` — so a drafted `en`
guide is titled **"Schoonmaken"** and the builder is then asked to write an English article about it.

| Drafting path | English title? |
|---|---|
| `bc:plan-coves` (observance-driven) | ✅ — `site.daily.observances.*` fully translated, all 100 |
| `PlanDrafter::fromInterests()` (personas) | ✅ — `site.gift.interests.*` in the market language |
| `PlanDrafter::fromCalendar()` | ✅ |
| `TopicPlanner::draft()` from seasonal or chart topics | ❌ — Dutch |
| search-log topics | ⚠️ — nothing yet; becomes the *good* source once English search works |

The proper fix (an English topic key on `cove_seasons`, a translated `chart_categories` name) is a separate
feature — a known gap, deliberately off this path. It is also the one item here worth doing on its own
merits: `be-fr` has the same Dutch topic names today.

---

## Order of work

Each step is a no-op while `SHOWCASE_MARKETS` is empty — every guard early-returns on an empty list. The
whole feature can be merged and deployed with the site behaving exactly as it does today; step 8 is the
decision to actually present it.

1. `Market::isShowcase()`/`showcaseKeys()`/`isIndexableAlternate()`, config key, `.env.example`, compose,
   `ConfigContractTest` assertion, `market.showcase` in the payload + `types.ts`
2. Migration (`title_en`, `title_en_source`, CHECK) + `scopeShowcaseReady()` folded into `presentable()` +
   the two `SearchService` sites + the `title` accessor
3. `BlockedOnShowcase` middleware + route annotations + `ScanController` redirect + `ModeRegistry` filter
4. The §6 server changes + `Alternates` + `SitemapController` + `MarketSwitcher`
5. React: `ProductCard`, `Search.tsx`, `Brand.tsx`, the two `footer.affiliate` sites
6. `WriteEnglishTitles` job + `ai.caps.english_titles` + `bc:write-english-titles` + the `applyTextMatch`
   English branch
7. `lang/en/site.php` value edits + `shop-coves.php` + `SeedShopCovesCommand` guard + a
   `docs/features/showcase-markets.md` entry, indexed in `docs/features/INDEX.md`
8. **Runbook (§11) against production data, verify `/en/` by hand, then flip the flag**

## Verification

**Tests** — all run with `showcase_markets` empty by default, so the existing suite is unaffected; showcase
tests set the config in `setUp()`.

- `tests/Unit/MarketTest.php` (extend): the flag flips only the named market; an unknown key (`en-gb,en`)
  is filtered out — that one guards the `whereNotIn` hole.
- `tests/Feature/ShowcaseMarketTest.php`:
  - **the server-side leak assertions** — with a merchant seeded under a distinctive name, walk every
    showcase-reachable path and assert the content carries no `narrative` block, no merchant facet, no
    `lanes`, no `/go/` link and no `/en/p/` link. These are the things §6 removes server-side, so they are
    genuinely testable.
  - **the byte-identical test** — render `/be-nl/search?q=koptelefoon` with the flag empty, capture, re-render
    with `['en']`, `assertSame`. This is what lets you ship without re-verifying three markets by hand.
  - the blocked routes 404 on `/en` and still answer on `/be-nl`; a group without `title_en` appears nowhere
    on `en` and is unaffected on `be-nl`; the partial-select case; the switcher is empty; `/en` declares no
    hreflang and `/be-nl` no `hreflang="en"`; the sitemap omits products and shops.
- `tests/Feature/EnglishTitleRewriteTest.php` — nothing happens with AI disabled; the batch selects only
  groups used in articles; provenance is stored; an editor title is never overwritten; a title naming a shop
  is rejected; an unasked-for id is ignored; the command refuses a non-showcase market.
- `tests/Feature/ShowcaseSearchTest.php` — an English query finds a group by `title_en`; a typo still matches;
  `Http::fake()->assertNothingSent()` proves no live connector is called; `be-nl` search unchanged.
- **Amended, each deliberate:** `LocalisationTest::every_page_declares_its_alternates()` (excludes showcase
  markets), whatever in `SeoTest` asserts `x-default`, `MarketSwitcherTest`, `ConfigContractTest`.

**By hand — this is the half the tests cannot cover.** With the flag set locally: `/en`,
`/en/search?q=headphones`, a guide, a persona, a brand page and the daily edition, checking that no price,
no shop name, no discount badge and no product link is drawn anywhere. Then `composer test` in full, since
this is production-bound.

**What the feature doc would have to record**, per the rule in `.claude/CLAUDE.md`, if this is ever built:
that showcase mode is a compliance posture, not a feature flag someone left on; why config and not a match
arm; why `presentable()` carries the English-title rule; why product pages 404 rather than render
price-free; why the showcase market is in no hreflang cluster and what that costs in `x-default`; **that
filtering is client-side by choice, so the payload still carries prices and merchant names — with the
reasoning, so nobody later mistakes it for an oversight**; that `AI_ENABLED=false` means an empty showcase
market, the one qualification to this codebase's "everything works with AI off" promise; the known gaps
(Dutch `cove_seasons` keys, the partial-select fallback, `Market::default()` still pointing here, user-typed
shop URLs on shared wish lists); and that turning it off is one empty env var plus reverting the
`lang/en/site.php` edit.
