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

## The five kinds

`App\Enums\CoveKind` is where the differences live, and there are only three of them:
how the page is addressed, how its products are chosen, and how many it needs.

| Kind | Address | Products chosen by | Floor |
|---|---|---|---|
| `daily` | `/daily/{slug}` | surprise + category spread | `picks.minimum` |
| `persona` | `/gift-ideas/{slug}` | surprise + category spread | `picks.minimum` |
| `guide` | `/guides/{slug}` | one per brand, price ladder | `guides.min_products` |
| `seasonal` | `/guides/{slug}` | one per brand, price ladder | `guides.min_products` |
| `advice` | `/guides/{slug}` | none — the prose *is* the substance | 0 |

Putting these on the enum rather than in the callers is what removed the
`isPersona() ? A : B` branch that had grown into four copies and would have needed a
third arm for every new kind.

## Two screens

**Content → Cove planner** is where a page is decided: what it is about, which
products, why each one is on the list, and how it should be written. It was called
"Cove calendar", which was right while a plan was a date and a title and is wrong now
that four of the five kinds have no date at all.

**Content → Cove editorials** is everything that has been published, whatever kind,
with tabs per kind and filters for market, status and date. It replaces two
navigation entries — "Daily Cove", which was nearly read-only, and "Guides", which
was fully editable — that answered different questions about the same job and neither
of which could answer "what is live in `nl-nl`".

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

- `app/Enums/CoveKind.php` — the five kinds and every difference between them
- `app/Filament/Resources/CovePlans/` — the planner and its curation screen
- `app/Filament/Resources/CoveEditorials/` — everything published
- `app/Services/Cove/Selectors/` — surprise versus ladder
- `app/Services/Cove/Writers/` — column versus guide
- `app/Services/Cove/EditionBuilder.php` — orchestration, redo, copy refresh
- `app/Services/Guides/TopicPlanner.php` — topic → draft plan
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
