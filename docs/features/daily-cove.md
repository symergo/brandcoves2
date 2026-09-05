---
name: The Daily Cove
area: Discovery / Content
status: Active
date_added: 2026-08-08
---

# The Daily Cove

**Daily Picks and buying guides, merged into one daily edition written as an article.**

Supersedes the separate Phase 5 (Daily Picks) and Phase 6 (buying guides).

## Why merge them

Kept apart, each has a hole the other fills:

- **Daily Picks alone gives no reason to come back.** Novelty wears off in about a week. A daily
  product feed is something people visit twice.
- **Buying guides alone have no audience on publish day.** They are pure evergreen SEO — real value,
  but a guide published to nobody takes months to earn its first visit, and nothing about them makes
  anyone return.

Together: the guide gets a daily audience the day it drops and evergreen traffic forever after; the
picks get a permanent, indexable home instead of scrolling into nothing.

## The shape: one article a day

### The editorial, with the products inside it

A theme, then prose, and each product where the prose is about it.

The page used to be an article followed by a grid: everything the writing was *about* sat below
everything the writing *said*, so a paragraph discussing a kettle pointed at a card three screens
down. That is a catalogue with an introduction.

The pairing was already in the copy and unused. `[[product:12]]` is the writer — a model, or an
author through [the editorial API](editorial-api.md) — saying "this paragraph is about that thing";
`CoveMarkup` resolved it to a link and threw the association away. `DailyCoveController::editorial()`
now reads the ids back out per paragraph, so the product renders under the paragraph that names it.

Three rules, each one a way the naive version goes wrong:

- **Only ids the article was allowed to mention.** A token naming a product outside today's edition
  already renders as plain text rather than a broken link; it must not conjure a card either.
- **First mention only.** Copy that names the same kettle three times would otherwise stutter it
  three times down the page.
- **Whatever the article did not name keeps the grid below.** An edition can carry more finds than
  the copy gets to, and silently dropping them would lose products the builder deliberately chose.

### The guide

Today's buying guide, built from **what people actually searched on the site this week** — the
`search_log` clustering that was Phase 6. "The five best X, and the one actually worth it."

This is where the SEO value lives. Every edition has a permanent URL, so the archive is a growing
corpus of indexed pages, each one a guide plus a set of products plus the writing that connects them.
Ninety days in, that is ninety pages per market that did not exist before, each answering a question
someone demonstrably asked.

### The address

`/{market}/tips/{slug}` — `/nl-nl/tips/de-laatste-vakantiedag`.

One word for every market. `tips` reads in all four languages the site is written in, and it is short
enough not to dominate the path. See `Market::coveSegment()`.

It used to be `/daily/`, which was worse: a path segment is read by a person deciding whether to
click and by a search engine deciding what the page is about, and "daily" did neither job outside
`/en`. The site's own name for the section — The Daily Cove — is not the answer either, because
nobody searches for "cove". The brand keeps the page, the heading and the newsletter.

Between those two it was localised per market — `cadeautips`, `idees-cadeaux`, `gift-tips`,
`ideas-regalo` — for about two hours on 2026-09-03. That version optimised harder for search and paid
for it in every other way: four strings to keep in step, a route pattern that had to admit all of
them, a rule about one market's word appearing in another's URL, and `CoveKind::path()` needing the
market passed through a dozen call sites. Collapsed to one word deliberately and **early**, while the
URLs were hours old and barely crawled. The same decision a month later would have meant abandoning a
real archive rather than an afternoon's worth of it.

Two words were considered and rejected. `cadeau-van-de-dag` for length — seventeen characters in
every archived URL to say what the slug and the page already say. `gift-ideas` because it is the
persona shelf.

### Three names for one page

The page has three names and they are deliberately not the same string:

| | what it is | example |
|---|---|---|
| `h1` | the edition's editorial name | De laatste vakantiedag |
| `og:title` | the same — a social card is not a search result | De laatste vakantiedag |
| `<title>` | the name plus a phrase people type | De laatste vakantiedag — cadeautips · GiftCoves |

Collapsing them is the trade everyone assumes they have to make: keep the writing and rank for
nothing, or stuff in keywords and sound like every affiliate site. It is a false choice. The tags are
separate mechanisms — `<title>` comes from the React page's `<Head>`, `og:title` from `PageMeta` —
so the writing keeps its voice *and* the search result carries the keyword.

`site.daily.seo_title` holds the pattern, per market, because it is copy. The brand suffix is
appended by the Inertia title callback and is not repeated in the string.

**The slug follows the headline, and is not separately keyword-stuffed.** That is a decision, not an
omission. The address already reads `/nl-nl/tips/…`, so a slug carrying the same words gives
`/tips/cadeautips-de-laatste-vakantiedag` — the keyword twice, adjacently, which is the
clearest over-optimisation signal there is. The slug instead inherits whatever the headline gained.

Which is where the real change is: the theme prompt now requires the title to **name something
concrete** — the occasion, the room, the category, or who it is for. "The last day of the holidays"
names a mood; "the last day of the school holidays" names a thing. Both are honest and only one is
findable. The prompt also forbids stuffing in "gift", "present" or "buy", because the page and its
address already say that.

**Three addressing rules, all load-bearing:**

- **The dated form redirects to the named one.** `/{segment}/2026-08-29` → `/{segment}/{slug}`, 301.
  Registered *before* the slug route, or `2026-08-29` is swallowed as a perfectly valid slug.
- **Everything under `/daily/` is kept forever** and redirects in **one hop** to its final address —
  not via the new dated form. Those URLs are indexed and sit in three months of digest emails, and a
  redirect chain costs link equity.
- **Every retired spelling still resolves.** The routes are declared once under a `{market}` prefix
  and the pattern admits the current word plus `Market::HISTORICAL_SEGMENTS`; anything that is not
  the current word is a 301, not a 404. That list is not per-market on purpose —
  `/es/cadeautips/...` was never a valid address, but redirecting it costs nothing and is one fewer
  rule to hold.

Two traps worth knowing if you touch this. `CoveKind::path()` takes the market as a **required**
argument — a leftover from when the segment varied by market, kept because it makes the dependency
explicit if it ever varies again. And Laravel binds controller arguments **by position, not by
name** — the segment and legacy dated routes carry different parameter lists, so they are two
methods rather than one with an optional parameter. Sharing a signature hands `$date` the segment,
which 404s a URL whose route is registered and whose regex matches.

## The column beside the article

Three cards, from `lg` up, stacking under the article below it. The article keeps
its `max-w-2xl` measure — prose past roughly 70 characters a line is harder to
read — and the column uses the space that cap was already leaving empty.

**The whole edition body goes in the left column, not just the prose** — and
that is what lets the rail stay a rail. When the column held the header and the
editorial alone, the split was balanced on the assumption that the writing would
always run long enough to reach past the cards beside it. It does not: the
29 Aug 2026 edition published with `editorial` empty, because nothing had
written it yet, so the column ended a few lines under the headline while the
sticky rail ran on for another eight hundred pixels — and the finds grid,
sitting outside the two-column grid, waited below all of it. The page read as a
headline, a void, and then the products.

With the finds and the guide inside the column it is the taller of the two
whatever the copy does, which is the way round that cannot leave a hole. Only
the cards for the recent editions and the subscribe box under them stay full width,
below both columns — see [cove-rail.md](cove-rail.md), which is where the archive strip that used to
run across the bottom of this page went.

**Biggest drops right now.** "Newest highest discounts" is two orderings that
fight: the deepest discount in the catalogue may be a month old, and the newest
may be 4% off. Sorted by discount *within* a fortnight's recency window, so the
column is both fresh and worth looking at rather than a stale hall of fame. Every
figure is against our own 30-day median, never a shop's crossed-out price — the
same rule the badges and the brand pages hold to, and the reason a saving shown
here can be defended.

## Six finds, three across

`picks.per_day` is **6** because the grid is three wide and stops at two rows.
Seven left one card alone on a third row, which reads as a product that failed
to load rather than as the end of the list — the count is a layout fact before
it is an editorial one.

Three is what the column can hold. Sharing the container with a 20rem rail
leaves a card at 240px, and the price and the save control need 184px on the row
they share; four across was measured at 175px a card, which does not fit, and
buying the width back by moving the grid outside the column would cost the rail
the article beside it. Two across below `lg`. The grid slices to six as well as
configuring to six, because editions built when the count was 7 are still in the
archive, and a plan can carry live Amazon items on top of its catalogue picks.

Products the editorial *names* are not in this grid — they render as figures
inside the prose, under the paragraph that is about them — so a well-written
edition shows fewer than six cards here. That is the intended trade: the grid is
what the article did not get to.

**Thumbs, not faces.** The two reactions render 👍 and 👎; they were 🤯 and 😐.
Read at a glance beside a price, "is this any good" carries where "did this
astonish you" did not. `Reaction`'s case names and the `mindblown_count` /
`meh_count` columns keep the old vocabulary deliberately — renaming them is a
data migration across `pick_reactions` and two counters for a change nobody can
see, and `Reaction::emoji()` is the one place the two spellings have to meet.

### The theme fills the page, and the curator's picks are not trimmed — 2026-09-04/05

Until now the theme was **a bias, not a filter**: themed finds led, and every slot they did not fill
was topped up from the general surprise pool, ordered by `surprise_score` across the whole market.
The argument for it was that a page that did not appear is worse than one where two of six finds are
off-theme.

That is a fair trade at two of six and a different thing entirely at four of six, which is what
nl-nl published on 4 September 2026. The plan was a home-gym theme with a five-product shortlist and
45 matching products in the market; the edition carried two dumbbells and then a party game, a
children's laptop, a pizza peel and a set of skate wheels — the four highest-scoring oddities in the
catalogue that morning. A reader who opened an article about home gyms found four things with no
relation to it, which does not read as serendipity. It reads as a page assembled by nobody.

**Two rules were doing this together, and both changed.**

1. `spread()` allowed **one product per category** and applied that to the curated shortlist as
   well. The feed's categories are leaf labels, so a themed day is one or two of them: the curator's
   five products were three *Dumbbells* and two *Hometrainer*, and three of the five were dropped
   before the engine added anything. **Curated products are now the trim's `lead`** — on the page
   whatever it decides, in the curator's order. Their categories still count as spent, so the engine
   does not pile a fourth dumbbell onto a shortlist already three deep in them, but nothing a person
   chose is discarded by a ranking rule. Curation exists to override the engine's judgement, and
   "one per category" *is* the engine's judgement.
2. Everything `spread()` could not fill from the theme came from the general pool. Now the trim
   **backfills from the remaining themed candidates** instead, so a Cove returns what its own theme
   can carry, however short that is.

Together they turn the gym edition into the curator's three dumbbell sets, their two exercise bikes,
and one more gym product — instead of one dumbbell set, one bike and four strangers.

Worth knowing if you are reproducing this: the first fault needs a **rich** candidate pool to appear
at all. `spread()` ends with a backfill pass, so on a thin day the products its variety pass skipped
came straight back and the shortlist survived by accident. It only lost products when the page filled
before the backfill ran — which is exactly what a market-wide surprise pool guarantees.

**The pool survives as the floor that keeps the column publishing at all**, and the threshold is
`picks.minimum` — the same number the builder refuses to publish under. Below it there is no edition,
so an off-theme find is the difference between a padded page and no page, which is the one trade
where padding wins. That floor is load-bearing rather than theoretical: the observance calendar's
queries are **Dutch**, deliberately (`config/observances.php` says so), so on an unplanned day in
`en` or `es` the themed lane matches nothing at all and the whole edition is the pool.

**Gift personas too**, since 2026-09-05 — `SurpriseSelector` fills both. The reason is sharper
there than on a Tuesday: a persona page is titled after a person, so a high-scoring stranger under
*The herbalist* is not serendipity, it is a page that does not know who it is about. Buying guides
are unaffected; they use `LadderSelector`, which never had a surprise lane.

Locked plans are unaffected — they never reached the selector. Nor is the guide side: `LadderSelector`
already treated the shortlist as an untrimmed lead and spent its one-per-brand rule around it, which
is where the shape of the fix came from.

### Only what you can buy

An edition is built once and served all day, and forever after in the archive.
Nothing re-checked stock at render, so a pick that sold out at eleven carried on
being presented as an ordinary buyable product — price, shop count and a save
button — for the rest of its life. The finds are now filtered on `in_stock` at
render, on the Cove, on the front page's four, and in the digest email, where an
out-of-stock pick is counted into "and more on the page" rather than named.

**Hidden here, dimmed in a guide** — the opposite treatment, deliberately. A
guide is a ranked list whose copy names each entry, so removing number three
breaks the writing; `GuideController` marks it unavailable instead. A Cove's
finds are a set, and one fewer card costs nothing. The prose keeps its link
either way: a product page for something out of stock is a real page, with the
price history and the restock alert on it.

### Why percentage alone is the wrong ranking

Shipped on percentage alone, the column filled with silicone phone cases. A €25
median down to €4.70 is an honest 81%, and a useless thing to put beside an
article. The percentage was never the problem; what it was applied to was.

Four filters, in `giftcoves.deals`, each removing one kind of junk:

| Rule | Removes |
|---|---|
| `min_price` €20 | Cheap things whose percentage says more about the price point than the offer |
| `min_saving` €10 | Big percentages hiding small money |
| `comparable()` | A median drawn from one shop — that is the shop's opinion, and a discount against it is its marketing |
| `worthShowing()` | Printer cartridges, beside gift writing on a gift site. Not `giftable()`: a deal is a deal at any price, and a heavily discounted expensive thing is the best row this column can carry. See [giftability.md](giftability.md) |

Then **one product per brand**, over-fetching ten times the limit so the cap does
not leave the column short. Six covers from one maker is one fact repeated six
times — the same reasoning as taking one feed per advertiser rather than the six
largest feeds.

The result on the live catalogue went from four phone cases in the top six to a
robot vacuum at €599 → €299. `DailyDealsTest` pins each rule, because thresholds
rot silently as a catalogue changes and nothing on the page would look wrong.

**The Gift Cove.** The one part of the site a reader here has no reason to have
found: the nav names it and nothing explains it. Four tool names and a link do
more than a nav entry ever did, and it sits beside what they are already reading
rather than interrupting it.

### The subscription card had no copy at all

`cove.subscribe_*` resolved to nothing, in all four languages, because a second
`'cove' => [...]` block — the Gift Cove, added months later — silently replaced
the first. PHP takes the last value for a duplicate array key and says nothing.
The Gift Cove block is now `gift_cove`, and
`no_language_file_declares_a_top_level_key_twice` fails the build if it happens
again.

## The price guess, and its removal

The edition opened with a game: one product with its price hidden, a few tries, feedback in bands,
then a shareable emoji grid. Removed in August 2026 at the owner's request.

Recorded because the removal cost two specific things, and anything proposed to replace it should be
measured against them rather than against a blank page:

- **The return reason.** Streaks were the only mechanism that made yesterday's visit predict today's.
  Novelty alone wears off in about a week; a daily product feed is something people visit twice.
- **The share artefact.** A row of squares is a *score*, not a link-beg — no spoiler, so posting it
  costs the poster nothing, and the same puzzle for everyone that day makes a posted result a
  conversation. The subscription email and the archive now carry the whole return loop by themselves.

Gone with it: `ChallengeController`, `PriceHunt`, `GuessBand`, `ChallengeAttempt`, the
`POST /{market}/daily/{date}/guess` route, the builder's compliance-gated subject selection, the
`challenge` key on the editorial API, the puzzle flag on the home page and in the digest email, and
the `daily.hunt_*` copy in four languages.

**The schema is still there, deliberately.** `challenge_attempts`, and `daily_pick_sets.challenge_*`.
Migrations here are forward-only and non-backwards-compatible changes go through expand/contract, so
dropping the tables in the same deploy that removed the code would leave a rollback facing a schema
the previous image cannot read. The drop is a separate, later migration; nothing writes to them now.

## The calendar: a theme for every day

An edition that opens with "Today's picks" and nothing else gives nobody a reason to return, so
**every date of the year resolves to a theme**. Two mechanisms, deliberately unlike each other:

| | Named days (`config/observances.php`) | Evergreen themes (`config/cove_themes.php`) |
|---|---|---|
| Count | ~100 | ~54 |
| What it claims | A fact about the date | Nothing about the date |
| Failure mode | Wrong in public, once a year, forever | None — "the desk reset" is true on any Tuesday |
| Copy | Hand-written, checked | Hand-written, seasonal |

`ObservanceCalendar::themeFor()` returns the named day if there is one and falls through to
`ThemeRotation` otherwise. `on()` still answers the narrower "is this a *named* day?", which is what
the "coming up" strip wants — nobody is counting down to the desk reset.

### Where the named days come from, and where they do not

**Not the UN international-day list.** It is the obvious source for filling 365 slots and it is the
wrong one: a large share of it is atrocity remembrance and disease awareness — Holocaust Memorial
Day, the Srebrenica genocide, victims of enforced disappearances, World AIDS Day. Real dates, and not
shopping occasions. Putting "today's finds" under a genocide remembrance banner is the kind of
mistake that ends up in a screenshot.

The list is drawn from the commercial and playful calendars instead — food days, fandom days, hobby
days, retail moments. One test decides an entry: *would a reader be pleased, rather than appalled, to
be sold something today?*

### How a date gets an evergreen theme

`ThemeRotation` shuffles the themes eligible for the month with a seed of (year, month, market), then
hands them out by day of month. Consequences worth knowing:

- **Deterministic.** The same date always yields the same theme, so a plan drafted in January still
  matches the edition built in June. `bc:plan-coves` would otherwise describe editions that never
  appear.
- **No repeat inside a month**, because the eligible pool is always longer than any month. A test
  asserts this — add a seasonal theme while removing an all-year one and that is what tells you.
- **Markets diverge.** The market is in the seed, so five markets do not run one identical calendar.
- Sorting is by `hash(seed, key)` rather than a seeded `shuffle()`, because `mt_srand`'s sequence is
  not guaranteed stable across PHP versions and this ordering has to survive an upgrade.

### Seasonal run-ups

A month tag is too coarse for the thing that actually sells: the weeks *before* an event. Nobody buys
a Halloween costume on 31 October, and the first warm weekend in May is when a paddling pool stops
being a silly idea. So a theme may carry a `window` of `MM-DD` dates (wrapping the year end if `to`
precedes `from`), and while the window is open its themes take roughly **one day in three** —
enough that the season is unmistakable, not so much that the site becomes a costume shop for a
fortnight. Named days still win outright, so 31 October is Halloween and not its trailer.

Windows in place: early summer, poolside, barbecue season, holiday packing, back to school,
pre-Halloween, autumn indoors, the Sinterklaas run-up (`be-nl`/`nl-nl` only), gift season, and the
January reset.

The slot decision is `hash(seed, day) % 3`, not `day % 3` — modulo on the day number would put the
seasonal slots on identical dates in every market and every year, which reads as a pattern the second
time you look.

### Copy, and what may be missing

Titles are mandatory in all five markets: `Observance::title()` falls back market language → English
→ **null**, and a theme with no title is simply not a theme, which the builder already handles. The
one-line blurb is optional and deliberately does **not** fall back to English — a Dutch heading with
an English sentence under it looks broken in a way a missing sentence does not. `LocalisationTest`
exempts exactly that one key shape and nothing else.

`fr` and `es` currently carry titles only. The AI editorial pass fills the prose; with `AI_ENABLED=false`
those editions run with a title and no blurb, which is correct rather than broken.

An evergreen theme is passed to the model as "today's angle … this is NOT a named day", because told
"the occasion: cosy" a model writes "today we celebrate cosiness" and invents a holiday.

## Curation: the products are chosen first

Since 2026-08-29 a plan carries an **ordered shortlist with a reason per product**, curated on its
own screen before the article is written, and the writer is told to cover it. This replaced
`cove_plans.pinned_group_ids` — a jsonb array of ids edited through an `ILIKE` dropdown that could
only see what had already been ingested.

The three things it changed here:

- **`finds()` reads `cove_plan_items`**, in the curator's rank order, instead of the pinned array.
  Curated products still lead and are still exempt from the 90-day repeat memory, for the unchanged
  reason: the point of curation is to override a score, so a pick the ranker could veto would not be
  curation.
- **`pick_mode` decides what the engine may add.** `open` tops the edition up to `picks.per_day`, from the theme alone unless that leaves the page under `picks.minimum` (above);
  `locked` publishes exactly the shortlist, in order, with `spread()` skipped so the variety trim
  cannot reorder a hand-built list. The publish floor is now `picks.minimum` in config rather than a
  literal 3, so the curation screen can warn about a short locked plan before 06:00.
- **The editorial prompt is handed the shortlist and its notes.** Every edition, curated or not, is
  told to write about every product in its own paragraph; what curation adds is the order and the
  reason each product is on the list. That rule used to flip — see
  [product-cards-in-prose.md](product-cards-in-prose.md) for why it stopped.

The full reasoning is in [cove-curation.md](cove-curation.md).

## The other kind of Cove

A **gift persona** is the same object with no date — built by this builder, from a plan curated on
the same screen, and served at `/{market}/gift-ideas/{slug}`. `daily_pick_sets.drop_date` is
therefore nullable, which introduced the one trap worth knowing about before touching any query in
this feature: Postgres sorts `ORDER BY drop_date DESC` **NULLS FIRST**, so every listing that means
"the daily column" now has to say `->daily()`. See [gift-personas.md](gift-personas.md).

## Compliance

Prices shown in an edition follow the ordinary rules: a source that requires a live re-fetch and
prohibits retained pricing is never displayed from storage. See
[amazon-compliance.md](amazon-compliance.md).

The game used to add a stricter constraint — its subject had to be a product whose price could be
*frozen* for twelve hours and then scored against — and `Source::allowsPriceTracking()` gated
selection for it. That gate is gone with the game; the general rule is unchanged.

## AI

Theme lines and the guide's editorial copy are the only AI-touched parts, and they run in the nightly
build job under the `daily_picks` and `guide_copy` caps. The edition builds and publishes with
`AI_ENABLED=false` — themes fall back to a curated rotation, guides to template copy. Choosing the
picks involves no model at all. See [ai-invariant.md](ai-invariant.md).

**Prose written by an author beats all of it.** A `cove_plans` row may carry the edition's editorial,
and when it does the builder uses it verbatim and skips the model entirely — not as a seed to
rewrite, not as a fallback. It lives on the plan rather than the edition because the edition's copy
is an output that every rebuild overwrites, and an author's words have to survive that. Written
through [the editorial API](editorial-api.md), a Cove therefore costs nothing in AI spend at all.

### Getting the prose back: `bc:refresh-guide-copy`

An edition is rebuilt every day, so a theme written during an AI outage is replaced by the next
morning's run. **A guide is not.** It is written once at publication and nothing revisited it, so a
guide built while the model was unreachable kept its template copy permanently, and no symptom on the
page said so: it renders, it simply has no editorial in it.

That was not hypothetical. Until `AiClient` stopped reading the answer out of `content[0]` — a
`thinking` block on any prompt long enough to warrant one — every guide fell back to the template
while the usage table showed successful calls, real token counts and zero errors.

`bc:refresh-guide-copy` re-attempts the prose. Daily at 04:40, eight guides a run, which clears a
backlog inside a fortnight without competing with the 06:00 editions for the day's budget. It also
serves as the monthly freshness pass the build plan calls for, since that is the same operation on a
different trigger.

Three rules it holds to:

- **The shortlist is never re-chosen.** Only the words change. Re-picking products would reorder a
  page Google has already indexed, and the new copy would describe a guide nobody ranked.
- **Existing copy is never traded for the template.** `GuideBuilder::copy()` reports whether the
  answer came from a model, and a run that could not reach one leaves the guide exactly as it was.
  Without that, every capped run would quietly strip prose from good guides.
- **The cap is checked per guide, not once up front.** Other features share the day's budget. Running
  on past it makes one failed call per remaining guide, each logged as if the model had let us down.

Guides with no editorial at all are served before stale ones. A stale but real paragraph is in far
better shape than none, and the cap means a run usually cannot have both.

## Schema

- `daily_pick_sets` — theme, editorial, `guide_id`, `kind` + nullable `drop_date`/`slug` (see gift
  personas), and the disused `challenge_*` columns above
- `daily_picks` — the finds, with their reaction counts
- `challenge_attempts` — disused; awaiting the contract migration
- `guides` / `guide_items` / `guide_topics` — linked from an edition
- `cove_plans` — the plan, with `kind` and `pick_mode`
- `cove_plan_items` — the curated shortlist, ordered, each with the reason it is there

## Status

Active. Editions build nightly and publish at the configured drop time.
