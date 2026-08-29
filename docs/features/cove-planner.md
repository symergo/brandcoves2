---
name: The Cove planner
area: Content / Operations
status: Active
date_added: 2026-08-30
---

# The Cove planner

**Every kind of page is decided before it is built, on one screen, by a person.**

Before this, the site published editorial through two pipelines that shared nothing.

A **Cove** was planned. `cove_plans` recorded what the page was for, a curated
shortlist in `cove_plan_items` with a note against each product, and a brief for the
writer. An editor curated, approved, and the builder published.

A **guide** was not planned at all. `TopicMiner` clustered the search log,
`SeasonalTopics` seeded a calendar of windows, an editor queued a topic — and then
the builder chose its own products, wrote about them and published. There was no
shortlist to curate, nowhere to record why a product was on it, and no way to tell
the writer what the piece was for. The only human control was editing the sentences
afterwards.

They are the same job. So there is now one planner, one curation screen, one builder
and one editorial table.

## The kinds

`App\Enums\CoveKind` is where the differences live, and there are only three of them:
how the page is addressed, how its products are chosen, and how many it needs.

| Kind | Address | Products chosen by | Floor |
|---|---|---|---|
| `daily` | `/daily/{slug}` | surprise + category spread | `picks.minimum` |
| `persona` | `/gift-ideas/{slug}` | surprise + category spread | `picks.minimum` |
| `guide` | `/guides/{slug}` | one per brand, price ladder | `guides.min_products` |
| `seasonal` | `/guides/{slug}` | one per brand, price ladder | `guides.min_products` |
| `advice` | `/guides/{slug}` | none — the prose *is* the substance | 0 |
| `shop` | `/shops/{slug}` | none — see [shop-coves.md](shop-coves.md) | 0 |

Putting these on the enum rather than in the callers is what removed the
`isPersona() ? A : B` branch that had grown into four copies and would have needed a
third arm for every new kind.

## Two screens

**Content → Cove planner** is where a page is decided: what it is about, which
products, why each one is on the list, and how it should be written. It was called
"Cove calendar", which was right while a plan was a date and a title and is wrong now
that every kind but the Daily has no date at all.

It carries **the same tabs as the editorial screen**: one strip per kind, and a second strip per
market beneath it, with a count on each. Dropdown filters for both were already there and answered
neither question the tabs answer: a filter is something you have to think to apply, and the count
that makes an empty section obvious cannot appear on one. "This market has no seasonal plans" is a
fact worth seeing without clicking. The two screens are the same list at two points in its life, and
an editor moving between them should not have to re-learn where things are.

**Content → Cove editorials** is everything that has been published, whatever kind,
with the same two tab strips and filters for kind, status and date. It replaces two
navigation entries — "Daily Cove", which was nearly read-only, and "Guides", which
was fully editable — that answered different questions about the same job and neither
of which could answer "what is live in `nl-nl`".

### Two strips, because kind and market are independent

Filament gives a list page one tab strip, bound to `$activeTab`.
`App\Filament\Concerns\HasMarketTabs` adds a second, bound to its own `$activeMarket` property and
composed into the page's `content()` schema — the only place both can be stacked. Sharing one trait
is what keeps the two screens identical.

Four decisions worth keeping:

- **The market dropdown filter was removed, not left alongside.** Two controls on one axis can
  disagree — tab on `be-nl`, filter on `be-fr` — and the empty table that results is a puzzle
  rather than an answer. The `kind` filter stayed only because the kind strip predates this and
  removing it is a separate decision.
- **The strips cross-filter each other's badges.** Standing on the Netherlands, the kind counts are
  Dutch counts. A badge that ignored the other axis would be a number no click can reproduce.
- **The market never narrows a record lookup.** Editing or deleting a row arrives with an id, and
  scoping *that* to the tab you happen to be on turns a stale tab into a 404 on a row visibly on
  screen. Filament's own kind tabs do apply there; this deliberately does not.
- **Every market gets a tab, including the unpublished ones.** `Market::published()` is for
  visitors; an editor's job includes filling the market that has not opened yet.

The choice rides in the query string as `?tab=guide&market=nl-nl`, so "the Dutch guides" is an
address you can paste to someone. **Draft some** defaults its market to the strip you are standing
on for the same reason it defaults its kind: ten plans drafted into the wrong market is ten rows to
undo by hand.

Guides were editable before the fold and still are: the shortlist is chosen by us and
the prose by a model, and the prose is the part that occasionally needs a human.

### Rebuild and redo are different things

They sit next to each other, so the wording is load-bearing.

**Rebuild** reproduces the page from its plan. Idempotent, routine — it is what the
scheduler does, what a redeploy does, and what the button does.

**Redo** deliberately throws the inputs away to get a *different* page at the same
URL. Four things had to be handled or it would silently do nothing:

1. A curated shortlist survives a rebuild — that is the point of `cove_plan_items` —
   so redo has to clear it.
2. Authored prose short-circuits the model, so "rewrite" with prose still on the plan
   writes nothing at all.
3. **Reselection is not automatically different.** A Daily lands elsewhere because of
   the ninety-day repeat memory. A persona and a guide are not in that memory, and
   the ladder is deterministic — so what is on the page now is passed as an
   exclusion. Without it a redone guide hands back the identical shortlist.
4. `published_at` must not move, or a page live for months re-dates itself and jumps
   to the top of every "newest first" shelf.

It also **destroys the reader reactions** on that Cove — deleting the picks cascades
`pick_reactions` — and there is no undo. Every surface that offers it says so before
it runs.

## Filling the planner: "give me ten more of these"

`CuratePlan` solved the blank page for the products on a plan — an editor opening an empty shortlist
is being asked to invent seven products from nothing. It left the same problem one level up
untouched: a market with no guide plans opens on an empty table, and the way out was to think of a
topic, type it, slug it, and do that nine more times.

Every kind already had a source of ideas. None was reachable from the screen where you would want
it. The observance calendar existed only as `bc:plan-coves`, a console command; the mined topic
queue lived under a different navigation entry behind a per-row button; and the interest vocabulary
the gift wizard runs on had never been used for editorial at all.

**Draft some** on the planner asks how many, defaults to the kind of the tab you are standing on,
and writes that many drafts. `App\Services\Cove\PlanDrafter` is the one implementation; the
[editorial API](editorial-api.md) exposes the same thing at `POST /coves/drafts`.

| Kind | Source | Ordered by |
|---|---|---|
| `daily` | observance calendar | the next unplanned themed days |
| `guide` | mined topic queue | measured demand, most first |
| `seasonal` | seasonal calendar | how soon the window opens |
| `persona` | `App\Enums\Interest` + `AngleMap` | the enum's own order |
| `advice` | — | refused, with the reason |
| `shop` | — | refused, with the reason |

Three things this is careful about.

**Drafts, never approved.** The point of drafting is that somebody reads it before it publishes, and
a button that produced approved plans would be a content farm with a nicer interface. Nothing here
calls a model either — it reads rows and writes rows — so invariant 1 is not even in play.

**Running out is a sentence, not a zero.** Asking for twenty personas in a market with four
interests left is not an error, but "4 drafted" with no explanation reads as one and the next thing
anybody does is press the button again. `DraftedPlans::shortfall` is written where the exhaustion is
discovered, because that is the only place that knows which source ran dry, and it names the command
that would produce more.

**Two kinds are refused rather than faked.** An advice article is an opinion about how to shop;
nothing in the catalogue or the search log proposes one, and generating titles from a template would
fill the queue with plausible-looking work nobody meant. A Shop Cove is seeded from the repository by
`bc:seed-shop-coves` and **nothing builds a Shop plan** — `BuildCove` has no arm for it and
`buildArticle()` excludes it by design — so a drafted one would sit in the planner unbuildable.

### Why a persona is drafted from an interest

A persona is a page about a *person*, and no enum will ever produce "the cottagecore herbalist". What
`Interest` can produce is the twenty subjects the gift wizard already knows, translated into every
market's language and each mapped by `AngleMap` to concrete product nouns — and those nouns are the
whole point. "photography" as a query retrieves gift listicles; `statief, cameratas,
polarisatiefilter` retrieves products.

So the title is a placeholder and says so in the note, which asks to be renamed. The interest is read
back **out of that note** rather than out of the title, precisely because the rename will happen: a
second run that matched on titles would re-draft every persona somebody had improved and skip the
ones they ignored.

`bc:plan-coves` is deliberately *not* folded into this. It answers a different question — fill every
themed day in a window, across all markets, as a calendar — where this takes N ideas for one market
and one kind, as a queue top-up.

## Every published Cove has a plan

Including the ones nobody planned. The 06:00 build mints one as a record
(`CovePlan::recordFor()`), and a backfill migration minted one per existing edition,
so the planner describes the past as well as the future and anything live can be
re-curated.

A minted plan is `used`, never `approved`. `approvedFor()` is what decides whether a
plan *drives* the next build, so marking a record approved would turn the machine's
own output into an editorial instruction the next rebuild obeys — pinning tomorrow's
theme to today's.

**It carries no products.** Seeding the shortlist from what was published is
tempting and wrong twice over: a curated item leads the page and is exempt from the
repeat memory, so every automatic Daily would become a curated one and the next
routine rebuild would republish exactly what it was meant to refresh. And
`cove_plan_items.note` means "why a person chose this" — nobody chose these, a ranker
did. The curation screen fills a shortlist from the engine in one click.

## The topic queue is an idea feed now

**Content → Cove topics** used to publish. Queue a topic and one night the builder
chose its products, wrote about them and put the page live.

Its action is now **Draft a plan**: the topic supplies the three things only it knows
— the phrase people actually typed, the season it belongs to, the measured volume —
and hands over a draft plan pre-filled with the shortlist the builder would have
chosen, for a curator to react to. `TopicPlanner` does the join.

The consequence is worth stating plainly: **nothing publishes an article
automatically any more.** `EditionBuilder` no longer builds a guide inside the Daily's
06:00 job; it only features one that is already live. A market whose planner is empty
eventually has no guide to feature.

## A Daily Cove is addressed by name

`/be-nl/daily/2026-08-29` told a reader nothing and a search engine less. The page is
"Rond de tafel"; the date is when it happened. It is now
`/be-nl/daily/rond-de-tafel`.

The date stays as data — one per market per day, ordered and archived by it, and
`/daily` with no segment is still today's. Only the URL changed, and the old dated
form **301s** to the named one because it is indexed and sits in three months of
digest emails.

The slug comes from the *title*, not from `theme_slug`: that column is the rotation's
internal key and is English in every market (`theme-board-games`), which is a filing
reference wearing a URL. It is assigned by the model on create and never rewritten —
a page whose address changes when somebody presses rebuild is a page every existing
link now misses.

## One slug namespace per market

The partial unique index on `(market, slug)` covers every kind. A persona and a guide
cannot share a slug even though they live at different paths. It is the simpler rule
and it keeps `[[guide:slug]]` unambiguous about which page it means. The fold
suffixes rather than drops on a collision — `ON CONFLICT DO NOTHING` would answer a
clash by deleting a published page.

## Files

- `app/Enums/CoveKind.php` — the kinds, and every difference between them
- `app/Filament/Resources/CovePlans/` — the planner and its curation screen
- `app/Filament/Resources/CoveEditorials/` — everything published
- `app/Services/Cove/Selectors/` — surprise versus ladder
- `app/Services/Cove/Writers/` — column versus guide
- `app/Services/Cove/EditionBuilder.php` — orchestration, redo, copy refresh
- `app/Services/Cove/PlanDrafter.php` — a kind and a number → that many draft plans
- `app/Services/Cove/PlanSlugs.php` — the one place that respects the slug namespace
- `app/Services/Guides/TopicPlanner.php` — topic → draft plan
- `app/Http/Controllers/Api/CoveDraftController.php` — the same thing over HTTP
- `app/Services/Content/GuideFold.php` — the one-time data move
- `database/migrations/2026_08_30_0001*` … `0004*`

## Open

- `guides` and `guide_items` still exist, unread. The contract migration that drops
  them is deliberately **not** in this change: shipping it alongside the expand would
  destroy the source rows in the same deploy that folds them, which is the failure
  expand/contract exists to prevent. Drop them a release later.
- `GuideKind` survives only as the editorial API's vocabulary, where it also carries a
  deliberately lower floor (three products for an authored guide, five for a
  generated one).
- Seasonal Coves have a window but nothing surfaces them by it yet.

## See also

- [cove-curation.md](cove-curation.md) — the curation screen in detail
- [daily-cove.md](daily-cove.md) — the column itself
- [scheduled-writing.md](scheduled-writing.md) — writing Coves from outside
- [prompt-bank.md](prompt-bank.md) — changing what the writer is told
