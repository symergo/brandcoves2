# Roadmap step 4: intent search

Strategy: [docs/strategy.md](../../docs/strategy.md), section 5 and engine E, F and G. Written
2026-09-26 as "intent everywhere", widened the same day with the natural-language search box (owner's
section 5) and the two list signals (owner's ideas F and G).

## Goal

"Gift for my sister who loves gardening, €30–€50" typed into search comes back as **Sister ·
gardening · €30–€50**, then results. That same structured object ("Gift → Dad → 60 → cooking →
€50–€100") drives search, gift landing pages and Cove product selection. And the lists people make
teach it: products saved with an intent carry that intent for everyone (F), and a person's own list
is the brief for a gift for them and links its products to each other (G).

Suggested order inside the step: parser and interpretation (A) → brief serialisation and filters
(1, 3) → list signals (B, C) → landing pages and Coves (2, 4). Each ships alone.

## Where we start

- The object exists: `App\Services\Gift\TasteBrief` (market, interests, vibe, preferences, budget
  min/max in cents, avoid, values, relationship, occasion, age band, exclusions, limit, profile,
  query). Built from a recipient (`fromRecipient`) or from the wizard's input
  (`GiftController::brief()`, l.385-418). It has no serialisation.
- `SuggestionEngine::suggest(TasteBrief)` is fast (under 100 ms), makes no AI call and needs no
  user: it can run in a web request.
- The vocabulary is `App\Services\Gift\GiftTags` (interests, events, recipient types, vibes,
  preferences), stored in `product_groups.gift_tags` with a GIN index.
- Search (`SearchQuery`) filters on price, merchant, brand, stock, discount; nothing about who or
  why. Guides and personas choose products from search terms only (`cove_plans.queries`,
  `LadderSelector`, `SurpriseSelector`). The Gift Whisperer's results are POST-only: not linkable,
  not indexable. No page like "gift ideas for dad" exists.

## Design

### A. Reading intent from what people type

`App\Services\Search\GiftIntentParser::parse(string $text, Market $market): ParsedIntent`. Pure,
unit-tested, no AI (it runs in the request).

- Word lists per language in `lang/{nl,fr,en,es}/intent.php`: recipients ("zus", "sœur", "sister",
  "hermana" → `RecipientType` / relationship), interests (synonyms → `Interest`), occasions
  (→ `EventType`), age ("60th", "60 jaar" → age band or milestone). Keyed to the closed `GiftTags`
  vocabulary, so the parser can only produce values the engine understands.
- Budget patterns: "€30–€50", "30-50 euro", "under 50", "onder de 50", "max 40", "tot €40",
  "moins de 50"; into cents (invariant 7).
- Trigger words ("gift for", "cadeau voor", "cadeau pour", "regalo para") mark gift intent; without
  one and without a recipient, the text is an ordinary product search and nothing is parsed.
- Returns the recognised pieces, each with the words it came from, and the leftover words (which
  stay as the search term).

`SearchController`: when the parse finds intent, the page shows the interpretation as chips (each
removable, each editable into a filter) and the results come from `SuggestionEngine` with that brief
plus the leftover words as `query`; otherwise the ordinary search runs untouched. The chips'
state lives in the URL (1 below), so a result page can be shared. Placeholder text in the search box
changes to an example of the new form.

### B. A list's intent counts for its products (engine F)

- **Recording intent at the save.** `ItemSaver` gets an optional `intent` (a serialised brief) from
  the page the save came from: an intent search result, a gift landing page, the Whisperer. Stored
  on `wishlist_items.saved_intent` (jsonb, nullable). The list's own intent is derived at count
  time from its recipient (relationship, age band, interests) and `event_type`.
- **Counting.** A nightly `CountListIntent` job writes `product_intent_counts (market, group_id,
  tag, owners)`: for each product and each tag, the number of **distinct list owners** whose list
  or save carried that tag. Claims are never read (invariant 4).
- **Reading.** `SuggestionEngine` and the search tag filters read counts at or above a threshold
  (start at 5 distinct owners, in config with the reason) next to the editors' `gift_tags`, with a
  lower weight. Editors' tags are never changed by it.

### C. A person's own list is intent too (engine G)

- **A gift for somebody who keeps a list.** `TasteBrief::fromList(Wishlist)`: interests from the
  editor and crowd tags of the products on it, a budget band from their prices, brands as
  preferences, the listed products as exclusions (the list itself is shown first, not suggested
  again). Used by the Whisperer when the giver picks a recipient who has a list, and by a "more
  like this" block on a shared list for gift-givers.
- **Products linked through the people who want them.** The same nightly job counts co-occurrence
  on `mine` lists: `product_links (market, group_a, group_b, owners)`, kept only above the
  distinct-owner threshold and capped per product (top 50). Read by the product page's Related
  (step 3) and as a signal in `SuggestionEngine`.
- Only lists a person keeps for themselves (`ListKind::Mine`) count for G: a list for somebody else
  says what the giver guessed, not what anyone wished for.

### 1. The brief becomes a value you can store and link

- `TasteBrief::toArray()` / `fromArray()` (validated against `GiftTags`, unknown values dropped).
- `App\Services\Gift\BriefUrl`: a brief ↔ a readable path for the fields that make sense in a URL
  (recipient, interest, occasion, budget band), the rest as query parameters. Words come from the
  lang files, per market, the way `SearchUrl` does it.

### 2. Gift landing pages

`/{market}/{gift-ideas word}/for/{recipient}` and `…/for/{recipient}/{interest}`, optionally
`?budget=50-100`. Rendered by `SuggestionEngine` from the brief in the URL, cached per market and
path for a day.

- **Only combinations that are worth a page exist.** A nightly `PlanGiftLandingPages` job walks
  recipient × interest (and recipient × occasion), runs the engine, and records the combinations
  that return at least eight distinct products (the owner's minimum for a Cove) in
  `gift_landings (market, path, brief jsonb, product_count, checked_at)`. Anything else answers
  404; that keeps thousands of thin pages out of the index.
- **Title and heading** lead with the searched phrase: "Gift ideas for dad who loves cooking". A
  short intro sentence is built from the brief by template, no AI in the request. A landing page
  can later receive an authored intro over the editorial API (same rule as Coves: written in the
  session, sent authored).
- Sitemap entries for recorded combinations; hreflang only between markets that both have the
  combination.
- The Whisperer gains "Open as a page" on its results: a GET link to the matching landing page, or
  to a noindex `/gift/for?…` result page when the brief is too specific for a landing.

### 3. Search filters

`SearchQuery` gains `for` (recipient type), `interest`, `occasion`, validated against `GiftTags`.
They filter with `jsonb_exists_any(product_groups.gift_tags, …)` in `SearchService`'s group query.
Filtered search pages keep canonicalising to the bare term (`search-urls.md`), so the landing pages
own those phrases in search engines, not filtered search. The Search page shows them as chips.

### 4. Coves chosen by intent

`cove_plans.brief` (jsonb, nullable). When present, `EditionBuilder` fills open slots from
`SuggestionEngine` with that brief instead of `SurpriseSelector` over queries; curated items still
come first. The editorial API accepts `brief` on `POST /coves` and `PATCH /coves/{id}` (and
`POST /coves` must keep sending it whole: it resets what it is not sent). Personas are the first
users: "the keen cook" becomes a brief, not a list of search terms.

## Decide before building

1. The URL words per market for gift landing pages, and whether they sit under the existing
   `/gift-ideas/` (personas live at `/gift-ideas/{slug}`: a `for` segment avoids collisions).
2. Which combinations to open first. Recommended: recipient × interest only, then occasions.
3. Gift tags are written only by editors today, so coverage limits every page here. B and C are
   the no-AI answer that grows with use; is a tagging pass (AI, queued, capped) still wanted on top?
4. Do private lists count in B and C? As anonymous counts above a threshold they reveal nothing,
   but the privacy page has to say so either way.
5. The distinct-owner threshold for a tag or a link to count (5 suggested).

## Files

- New: `app/Services/Search/{GiftIntentParser,ParsedIntent}.php`, `lang/*/intent.php`,
  `app/Jobs/CountListIntent.php`, migrations for `wishlist_items.saved_intent`,
  `product_intent_counts`, `product_links`; `app/Services/Gift/BriefUrl.php`,
  `GiftLandingController`, `app/Jobs/PlanGiftLandingPages.php`, `gift_landings` migration,
  `Pages/GiftIdeas/Landing.tsx`.
- Change: `TasteBrief` (serialisation), `SearchQuery` + `SearchService` (tag filters), `Search.tsx`
  (chips), `GiftController` + `Wizard.tsx` (open as a page), `EditionBuilder` + `CovePlan`
  (`brief`), `routes/api.php` editorial validation, `SitemapController`, `Alternates`,
  lang files (four languages).
- Docs: new `gift-landing-pages.md`; update `gift-whisperer.md`, `search.md`, `search-urls.md`,
  `gift-personas.md`, `editorial-api.md` and the seed-coves skill's `reference/api.md`, `INDEX.md`.

## Verification

- Unit: `GiftIntentParserTest` (the owner's example in all four languages, budget forms, a plain
  product search left alone), `TasteBriefTest` round trip, `BriefUrlTest` (every market, unknown
  values dropped).
- Feature: `ListIntentCountTest` (below the threshold nothing counts; one person with ten lists is
  one owner; claims never read; `for_someone` lists do not feed product links),
  `BriefFromListTest`.
- Feature: `GiftLandingTest` (a recorded combination renders with 8+ products, an unrecorded one
  404s, title within budget), `SearchTagFilterTest`, editorial API accepts and keeps `brief`,
  `EditionBuilder` with a brief never calls AI (`AiClient` fake asserts nothing sent).
- By hand: run `PlanGiftLandingPages` on dev data, open five landing pages per market, check the
  sitemap lists exactly the recorded ones.
