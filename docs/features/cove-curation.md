---
name: Curating a Cove
area: Content / Operations
status: Active
date_added: 2026-08-29
---

# Curating a Cove

**The products are chosen by a person, first. The article is written about them, second.**

Until now it ran the other way: the Serendipity Engine picked seven finds at 06:00 and the model
wrote whatever it could about whatever it was handed. A plan could carry `pinned_group_ids`, but that
was a hint bolted onto a machine-first pipeline, not a way of deciding what a page is.

## What was wrong with the pins

`cove_plans.pinned_group_ids` was a jsonb array of integers, edited through a Filament
`Select::multiple()` running `title ILIKE '%term%'` against `product_groups`. Every one of its limits
mattered:

| Limit | Consequence |
|---|---|
| Searched the local index only | Anything not yet ingested was invisible — including everything bol sells |
| A dropdown of titles | No image, no price, no shop count. You picked a string and hoped |
| An array of scalars | No room for the reason a product was chosen, which is what the writer needs |
| Order was incidental | The article's running order was the ranker's, not the curator's |

The last one is the deep one. **A shortlist without a reason attached is a set of candidates; with
one it is a brief.** "Why this is here" is a judgement, and it is exactly the thing a scoring
function cannot supply and a writer cannot invent.

## The shortlist is a table

`cove_plan_items` — one row per product, carrying its `rank`, the curator's `note`, an optional
`verdict` ("best for small kitchens"), and who added it. `guide_items` is the same shape for the same
reason.

Two ways to identify a product, because sources differ in what may be kept:

- **`group_id`** — a product in our own catalogue. The ordinary case, including anything found live
  on bol, because that is folded into the catalogue by the same search that surfaced it.
- **`source` + `external_id`** — a source whose catalogue may not be mirrored. The *decision* is
  stored and nothing a visitor reads; title, price, image and availability are re-fetched at render.
  Invariant 6. A CHECK constraint enforces that a row has one or the other.

`group_id` cascades on delete, deliberately unlike `daily_picks.group_id`, which nulls: a pick is a
record of what was published and has to survive its product disappearing, while an item is an
instruction for a build that has not happened yet, and an instruction naming nothing is not worth
keeping.

`rank` is **not** unique. A unique `(plan_id, rank)` turns every reorder into a two-phase dance
around the index, for an ordering that only has to be stable. `PlanCurator::reorder()` renumbers from
1 on every move instead, because an ordering that accumulates gaps is one where "move this up"
eventually stops meaning anything.

## The plan arrives with products on it

`bc:plan-coves` drafts a plan per themed day *and* pre-fills its shortlist with what the builder
would have chosen for that day — the same themed queries, the same surprise ranking, the same repeat
memory.

This is the difference between curation being used and not. A plan that opens empty asks an editor to
invent seven products from nothing, which is the blank page, and is why the old pinned-products field
sat unused. A plan that opens with the engine's guess asks them to *react* — remove the two that do
not belong, swap one, write a note on the best — which is a job people are good at and fast at.

The suggestion is also the safe default: leaving it untouched publishes exactly the edition that
would have published anyway, so every edit can only improve on it.

Two rules the planner holds to:

- **Nothing is suggested twice in one run.** The rolling repeat memory reads `daily_picks`, and none
  of the drafted days has been built yet — so without an in-run exclusion the highest-scoring seven
  products in the market would be offered for all hundred plans, and the calendar would be one
  edition repeated. Tracked per market, because `product_groups` is unique on `(market,
  identity_key)` and the same product in two markets is two different rows.
- **A plan that already has items is never appended to.** `PlanCurator::prefill()` refuses one
  outright. Re-running the planner is routine and idempotent; a second set of seven arriving
  underneath somebody's curation is the kind of edit only noticed after the page has published.

`--no-products` drafts the themes alone, for when you want the calendar and not the suggestions.

> **It needs a scored catalogue.** The candidates come from `surprise_score`, which
> `bc:refresh-discovery` writes. On an environment where that has never run, the planner drafts the
> themes and finds almost nothing to put on them — which looks like a broken feature and is an empty
> input. Run `bc:refresh-discovery` first; it is already the documented first command for a new
> environment, and this is one more reason.

## The search reaches every merchant

This is the part that makes curation possible rather than merely tidier, and it is almost entirely
reuse. `CurationSearch` builds a `SearchQuery` and hands it to `SearchService`, which already:

- calls every live connector configured for the market, skipping any that is unconfigured, disabled
  or backing off after a 429;
- **folds** the mirrorable half into `products` / `product_groups` in the same request;
- attributes brands to sources that supply none (bol's catalogue API returns no brand at all);
- returns the unmirrorable half separately, to be rendered and never stored.

So a bol product nobody has ever ingested can be searched for, found and pinned **in one request** —
by the time the results render it is a real group with a real id, offers behind it and a comparison
on its product page. A second retrieval path here would have had to reimplement all of that and would
have drifted from the search a visitor gets, which is the search a curated page has to sit alongside.

Two settings on that query are decisions rather than defaults:

- **`logged: false`.** `search_log` is the site's demand signal: it decides which buying guides get
  written and what the related-search chips say on public pages. An editor curating "kerstcadeau man
  40" all afternoon would otherwise manufacture demand nobody expressed — and afterwards there is no
  way to tell the invented rows from the real ones.
- **`discountedOnly: false`.** `SearchQuery`'s constructor defaults it to *true*, which would
  silently hide every full-price product from the curator. Curation is not a deals page.

Known and accepted: `SearchService` gates the live fold on a cache key with `search.live_cache_ttl`,
so if a visitor searched the same term minutes ago the curator gets the stored result without a fresh
live call. That is the same catalogue, so it is correct — but it explains why a curation search
occasionally does not hit the network.

## The screen

`/admin/cove-plans/{id}/curate`, and the calendar's rows link straight to it — curating is what an
editor opens that table to do, and editing a title is the occasional errand that keeps its own button.

**Two panes from `xl` up: the list on the left, the search sticky on the right.** The first version
stacked them, and curating meant scrolling down to search and back up to see what you had, once per
product, seven times a page. Nothing about that was broken and all of it was tiring.

### The layout needed a stylesheet that did not exist

Worth recording, because the symptom is misleading and the whole panel shares it. Filament ships a
**prebuilt** `public/css/filament/filament/app.css` containing only its own `fi-*` component classes
and **no Tailwind utilities at all**. Every `class="flex gap-3 rounded-lg"` in a custom panel page —
this screen's, and the three admin pages that predate it — was inert: the markup rendered, none of it
was laid out. It looks precisely like a page nobody styled, which is why it went unnoticed until the
two-pane layout visibly refused to split.

`resources/css/filament/admin/theme.css` now supplies them, registered with `->assets()` so it loads
*beside* Filament's stylesheet rather than replacing it. Two decisions inside it:

- **`@import 'tailwindcss/theme.css' theme(reference)`** — makes the theme available for generating
  utility names while emitting not one variable. Filament's own stylesheet already defines the
  Tailwind 4 variables these utilities reference (`--color-gray-500`, `--spacing`, `--radius-lg`),
  mapped onto its palette, so `text-gray-500` in a panel view is *Filament's* grey rather than a
  second, slightly different one.
- **No preflight.** It is a CSS reset, and resetting the panel out from under Filament would break
  far more than it fixed.

The obvious alternative, `make:filament-theme`, replaces the panel's stylesheet and imports Filament's
own from `vendor/` at CSS-build time. The Dockerfile's frontend stage copies `resources`,
`package.json`, `vite.config.js` and `tsconfig.json` and no vendor directory, so that route stops the
image building — and swapping the whole stylesheet to add a grid is a large lever for a small job.

### Every reversible action is reversible, not confirmed

Removing an item takes it off the list and offers **Undo**, which restores the product, its rank and
its note. A confirmation dialog charges a click on each of the six correct removals to protect
against the seventh — and could never have put the note back for the one somebody actually meant to
keep. Approve and Build still confirm, because those are not undoable: they put a page in front of
readers.

Notes and verdicts save on blur and say "Saved" in the row. They used to raise a toast, which turned
writing seven notes into a stream of notifications to dismiss.

### It says what will publish

`summary()` answers the question a curator has continuously and the first version could not:

> These 3 lead the Cove; the engine adds 4 more to reach 7.
> These 5 products, in this order, and nothing else.

The `open` / `locked` switch sits next to it, rather than only on the edit form, because "these four
*are* the page" is a thought you have with the four in front of you — not one you navigate away to
record.

### Suggest, so the page is never blank

**Suggest products** / **Fill the rest from the engine** calls `EditionBuilder::candidates()` — the
same selection the builder would make on the day — and tops the list up. `bc:plan-coves` already does
this for a drafted calendar; a plan created by hand in the panel arrives empty, and asking someone to
invent seven products from nothing is the blank page all over again.

It only ever adds. Existing items keep their position and their notes, and `candidates()` filters out
anything already on the plan — `finds()` puts curated products at the head of its list, which is
right when it is choosing an edition and would otherwise fill a top-up with things that cannot be
added.

### It warns about the mistake people actually make

Not picking a bad product — picking a good one **twice**. `ScheduleConflicts` marks any product that
is already on another plan for the market ("already on 12 Sep", or the persona's name) or was
published in the last `memory_days` ("ran 3 Aug"), on both the search results and the shortlist.

The 90-day repeat memory catches this for anything the *engine* picks and deliberately does not for
anything a person picks, because overriding a score is the whole point of curating. So the rule that
protects the machine protects nobody here, and telling the person is the only defence left. Advisory,
never a filter: two Coves a month apart may both want the same kettle, and a screen that refused
would be wrong more often than it was right.

### The rest

- **A budget field.** `€ up to` filters in SQL — the commonest constraint a curator works under, and
  better than scrolling past everything over it.
- **Product titles link out**, in a new tab, so "is this actually any good" is one click.
- **Move to top**, beside the up/down arrows: "open with this one" is the common edit, and six
  presses of an arrow is the interface making a person do the arithmetic.
- Results are cards — image, title, brand, price, shop count, and a **source badge**. The badge is
  not decoration: a product carrying a live source's badge is one the catalogue met seconds ago and
  has no price history for, which is a different thing to curate than one indexed for months.
- Search results are held in a Livewire property, not recomputed by the view. A component re-renders
  on every interaction, and a view that searched would call the rate-limited live connectors again on
  each one. Search is submitted rather than live-as-you-type for the same reason: debounced keystroke
  search reads as friendlier and would put a request to every merchant behind every pause in
  somebody's typing.
- The header warns *before* 06:00 about the two states that produce a bad morning: a locked plan
  under the publish floor, and curated products that have gone out of stock.

Nothing on the screen calls a model. The build is still a queued job — invariant 1.

## `pick_mode`: what the engine may add

A per-plan switch, because curation and ranking are both legitimate ways to fill a page and which one
is right changes by the day.

| Mode | Behaviour |
|---|---|
| `open` (default) | Curated products lead and are exempt from the 90-day repeat memory; the engine tops the edition up to `picks.per_day` from the themed and surprise lanes |
| `locked` | The curated products *are* the edition, in the curator's order. The engine adds nothing |

Under `locked` the variety trim (`spread()`) is skipped rather than applied to a shorter list. It
drops one-per-category, which on a hand-built plan would remove the fourth of four candles somebody
deliberately chose, and reorder the rest on the way past.

`locked` still respects the publish floor (`picks.minimum`, 3). The floor is about the reader, not
about who chose the products — a two-item page teaches a returning visitor that the column is not
worth opening, and that lesson outlasts the bad day that caused it. What changed is that the floor is
now visible on the curation screen instead of appearing as a log line at six in the morning.

## Instructions for the build

A plan already had three text fields and none of them was the one an editor
actually reaches for:

| Field | What it is | Reaches the model |
|---|---|---|
| `editorial` | the finished article | **no** — it replaces the model entirely |
| `note` | a note to whoever reads the plan later | no, deliberately |
| `items[].note` | why one product is on the list | yes, per product |
| **`build_instructions`** | **how this piece should be written** | **yes, once, for the whole article** |

The gap was the direction a person gives before the writing starts — "keep it short", "lean on the
nostalgia, not the tech", "do not mention Christmas, it runs in October". Without somewhere to put
it, an editor who wanted that had exactly one option: write the whole article by hand.

It sits **above** the two panes on the curation screen, because it is about the article rather than
any one product, and because it is what an editor decides first: what the piece is *for* comes before
which kettle goes in it. Collapsed when empty, so it costs nothing on a plan that does not need one.

Two things worth knowing:

- **It goes in the prompt, not the system message.** The system message carries the rules that may
  not be traded away — no prices, no invented claims, only the products listed. An instruction
  arrives as part of the brief the writer works to, underneath those, so "mention how cheap it is"
  cannot become permission to.
- **The screen says when it will not be read.** Authored prose wins outright and skips the model, so
  instructions on a plan that carries its own `editorial` are read by nobody. A field quietly doing
  nothing is worse than no field, so the section says so rather than accepting a brief into a void.

Also on the plan form and on the editorial API as `buildInstructions`, capped at 1000 characters: it
is a brief, and a brief long enough to be an article is an article.

## The writer is told what to cover

The curated list, in order, with each note, is passed to the editorial prompt as an explicit "write
about every one of these, in this order" block.

**This rule used to flip on curation and no longer does.** An engine-picked edition was told *"do not
list the products in order — pick two or three worth a sentence and let the rest speak for
themselves"*, which was right while the page was prose and then a grid: the grid carried whatever the
prose skipped. Since the card moved under the paragraph that names it, a product no paragraph
mentions has nothing written about it anywhere and drops to the foot of the page bare. So every
product is covered whoever chose it, and what curation adds is the two things a ranker could not
supply:

> Everywhere: *"Write about EVERY product listed below, each in its own paragraph, naming it with its
> link token."*
> Curated, on top: *"Take them in the order given"* and *"the note beside a product is the reason it
> was chosen — use it, do not quote it."*

See [product-cards-in-prose.md](product-cards-in-prose.md) for why the paragraph, rather than the
grid, is now where a product gets its writing.

If the returned prose names none of the products (checked on the `[[product:id]]` token, not on the
title — a title fragment can match by coincidence), the builder logs it and retries **once**. That
check used to apply only to a curated plan, for the same reason the rule used to flip.
Not a loop: the daily AI cap is shared with the guides and the trends pass, and a builder that argues
with the model spends the budget every other feature needs that day. If the second attempt is no
better the prose still publishes — it is about the right products, it merely did not link them, and
no prose at all is the worse outcome.

**Authored prose still wins outright.** A plan carrying `editorial` skips the model entirely, as
before. Curation feeds the writer; it does not overrule one.

## The editorial API

`POST /api/editorial/coves` takes `items: [{groupId | source+externalId, note, verdict}]`, ordered.
`pinnedGroupIds` is still accepted and written as items, so a key deployed before this change keeps
working — and validation errors are reported under whichever field the caller actually sent, because
being told your mistake is in `items` when you sent `pinnedGroupIds` is worse than no message.

A write **replaces** the shortlist rather than merging into it. A merge would make "remove the third
product" impossible to express, and a retry after a timeout would double the list.

`GET` reads the items back with their notes, so an automated author can fetch the brief and the ids
it may link to in one request instead of a search per product.

## Promotion between environments

`bc:export-content` / `bc:import-content` carry the items as portable `(market, identity_key)`
references, like every other product reference in the envelope. Without that, promoting editorial
would move a plan's title, blurb and prose and silently leave its products behind — and the plan
would look complete on the far side right up until it built a page nobody chose.

An item whose product does not exist in the target environment is **dropped and reported**, not
fatal: that environment may simply not stock it, and losing one item off a shortlist is a smaller
loss than refusing the whole plan.

## Files

- `app/Services/Curation/CurationSearch.php`, `CurationResult.php`, `PlanCurator.php`,
  `ScheduleConflicts.php`
- `app/Models/CovePlanItem.php`, `app/Enums/PickMode.php`
- `app/Filament/Resources/CovePlans/Pages/CuratePlan.php` +
  `resources/views/filament/resources/cove-plans/pages/curate-plan.blade.php`
- `app/Services/Cove/EditionBuilder.php` — `curated()`, `liveFinds()`, `curationBrief()`
- `database/migrations/2026_08_29_000100_create_cove_plan_items.php`,
  `..._000200_a_plan_has_a_kind_and_a_pick_mode.php`
- `tests/Feature/CovePlanCurationTest.php`, `CurationSearchTest.php`, `CuratePlanScreenTest.php`

## Open

- **`cove_plans.pinned_group_ids` still exists.** Backfilled from and no longer read. Dropping it is
  a separate, later migration — migrations here are forward-only and non-backwards-compatible changes
  go through expand/contract, so a rollback never meets a schema the previous image cannot read.
- **No Amazon connector is registered yet.** The `source + external_id` path is built and tested, and
  an unmirrorable pick becomes a `daily_picks.amazon_asin` row exactly as the schema always intended
  — but nothing exercises it in production until the connector lands.
- **An unmirrorable pick appears as a card, not as a sentence.** `[[product:id]]` resolves a group id
  and an Amazon product has none, so it cannot reach the prose. Deliberate, and worth knowing before
  someone curates a page mostly out of them.
