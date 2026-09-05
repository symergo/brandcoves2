# Where a Cove sends you next

Every Cove page used to end in a dead end of its own shape.

| Page | What it offered a reader who finished it |
|---|---|
| `/daily/{slug}` | a strip of chips linking past editions, below the subscribe box |
| `/gift-ideas/{slug}` | one bordered button reading "Gift Coves" |
| `/guides/{slug}` | nothing at all |

The third row is the one that mattered. An article is the Cove that search actually lands people on
— it is written from real query volume, and it is the only kind with a reason to rank — and it was
the page that said least about what else is here. A reader arrived from Google, read a buying guide,
and left.

Two surfaces now, filled by one service, `App\Services\Cove\CoveRail`, and rendered by two
components. They are in two different places on the page because they are two different sizes of
invitation.

## More Coves of the same kind, as cards under the article

`resources/js/Components/MoreCoves.tsx`, below the two-column grid, full width.

Reaching the end of a Cove is the strongest signal a reader gives, and what it asks for is another
one of these. A card can actually offer that: at the width the reading was, there is room for the
line of copy that says what the next one is *about*. A title in a gutter is not an offer, it is an
index. These are the same cards `/coves` uses, so the shelf looks the same wherever it is met.

**Its own kind only.** Somebody who has just finished a persona has told you what shape of page they
want, and answering that with three of each other shape is a table of contents rather than a
recommendation. [`/coves`](all-coves.md) is where the whole shelf is on show and it is one click away
in the header.

Six, because the grid is three across and two rows is where it ends. A third row past the bottom of
an article somebody has already finished is an index, and there is one of those a click away.

**Four bands out of six kinds.** A buying guide, a seasonal guide and an advice article are one
section to a reader — they share a URL space, an index and a name in the header — so `sectionOf()`
folds them into `smart`. Splitting them would leave an advice article offering only the other advice
articles, which on most markets is nothing. The band keys are `/coves`'s keys deliberately: the copy
for a heading and for "all of these" already exists as `site.coves.{key}_heading` and
`site.coves.{key}_all`, and a second set of names for the same sections would be more strings to keep
in step across four languages.

**Brands and shops are not bands here.** `/coves` publishes those two as directories, and a page of
writing that offered a grid of brand names would be navigation dressed as a recommendation.

**Ordering has to name its column.** `drop_date` is null on five of the six kinds and Postgres sorts
`ORDER BY ... DESC` **NULLS FIRST**, so ordering every band by it would head each one with whichever
dateless Cove the planner happened to write first. Editions sort by `drop_date`, everything else by
`published_at` — the same trap `DailyPickSet::scopeDaily()` documents.

## More products from the Cove's own categories, in the rail

`resources/js/Components/CoveRail.tsx`, in the sticky `20rem` column beside the article, under the
Gift Cove card.

In the rail rather than under the article because it is something to glance at *during* the reading,
not a conclusion to it. The writing named six things; the catalogue holds a few thousand more of the
same sort, and until now the only way out of an article towards them was to go back to the search box
and retype what you had just been reading about.

**Two categories, three products each.** A Cove's picks usually span two or three categories, and six
rows drawn from whichever one happened to sort first would answer "more like these" with more of a
third of it — on a Cove about a home office, six desk lamps and no notebooks. The two the shortlist
has *most* of win: a category represented by one product out of seven is a stray classification from
a feed rather than a subject.

**The categories come from the picks, not from the prose.** A Cove is about whatever its shortlist is
mostly made of, and `product_groups.category` is already the feed's own word for the shelf, in the
market's language — which is also why each band's heading links to `/search?q=<category>` rather than
to a category page. There is no category page; the search box is what turns one of these strings into
a browsable list, and it is the same destination a `[[search:...]]` token in the prose resolves to.

**`worthShowing()`, not `giftable()`.** This sits beside editorial rather than under "gift ideas
for", and the two answer different questions: a €700 espresso machine is a bad gift suggestion and a
perfectly good thing to show somebody reading about coffee. See [giftability.md](giftability.md).

**Most-compared first.** The one thing this site knows that a shop does not is what a product costs
everywhere, so a rail that leads with the rows carrying several offers is leading with the reason to
click. `first_seen_at` breaks the ties, which keeps the column turning over as the catalogue grows
instead of freezing on whatever was ingested first.

Nothing on the page is offered again — "more like this" that opens with what you just read is a bug
the reader can see — and an advice article, which may publish with no products at all, gets no block
rather than an empty one.

## What this replaced

**The Daily's archive strip is gone.** `DailyCoveController::archive()` and the `archive` prop were
deleted, and `site.daily.archive` with them. The cards *are* that strip — the same query, the same
handful of editions, the same links — so keeping both would have put one list on the page twice, once
beside the reading and once eight hundred pixels below it. The cards took the strip's place at the foot of the
page, and the subscribe box moved below them: the old "after the edition, before the archive" was
reasoning about a row of chips nobody was going to read past, and a grid of cards is where the page
actually ends.

**The persona's "Gift Coves" button is gone.** A button naming an index asks the reader to go and
look; six cards show them what is there, and the link to the whole shelf is the last line of the
block.

**The Gift Cove card left the Daily page and became everyone's.** It is the one part of the site a
reader of a Cove has no way of having found — the nav names it and nothing explains it — and there
was no reason it belonged only beside the edition. Its copy moved with it, from
`site.daily.gift_cove_hint` / `_cta` to `site.gift_cove.rail_hint` / `rail_cta`.

**Two pages grew a second column.** `/gift-ideas/{slug}` and `/guides/{slug}` were single-column;
both now use the Daily's `lg:grid-cols-[minmax(0,1fr)_20rem]`. The whole article goes in the left
column — shortlist, FAQ and all — for the reason [daily-cove.md](daily-cove.md) records learning the
hard way: when the column holds only the prose, a Cove published with short copy ends a few lines
under its headline while the sticky rail runs on for another screen.

## The series strip, above the article

A seasonal Cove is published as a series of parts — "Kamperen, deel 2" — and a numbered title with
nothing to number against is a promise the page does not keep. `CoveRail::series()` returns the
published parts in part order, current one marked, and `CoveSeries` draws them.

**Above the article, not in the rail**, and that is a decision about what the block is for. Everything
else here is somewhere to go *afterwards*; "which part am I reading" is something you need before you
start, and the title raises the question in the first line. It travels in the same prop because it
comes from the same service and the same request — splitting it out would make the two halves look
unrelated.

Three things it is careful about:

- **Null unless two parts are live.** One part is a page, not a series, and a heading over a list of
  one reads as a block whose contents failed to load. A series with part two published and part one
  still drafting therefore shows nothing, which is the right transient state.
- **The current part is text, not a link.** A link to the page you are on looks like the way forward
  and is the way nowhere. `aria-current="page"` carries the same fact to a screen reader.
- **It reads the plan, not the edition.** `series_key` and `part` are columns on `cove_plans`,
  because the series is a fact about how the work was *planned* and the edition is an output every
  rebuild overwrites.

See [seasonal-series.md](seasonal-series.md).

## Where it is

| | |
|---|---|
| Service | [app/Services/Cove/CoveRail.php](../../app/Services/Cove/CoveRail.php) |
| Cards | [resources/js/Components/MoreCoves.tsx](../../resources/js/Components/MoreCoves.tsx) |
| Rail | [resources/js/Components/CoveRail.tsx](../../resources/js/Components/CoveRail.tsx) |
| Pages | `Daily/Edition.tsx`, `GiftIdeas/Persona.tsx`, `Guides/Show.tsx` |
| Controllers | `DailyCoveController`, `GiftIdeasController::show`, `GuideController::render` |
| Copy | `site.coves.{key}_heading`, `site.coves.{key}_all`, `site.coves.rail_products`, `site.gift_cove.rail_hint`, `site.gift_cove.rail_cta`, `site.guides.series_heading` |
| Tests | [tests/Feature/CoveRailTest.php](../../tests/Feature/CoveRailTest.php), [tests/Feature/SeasonalSeriesTest.php](../../tests/Feature/SeasonalSeriesTest.php) |

`GuideController::shop()` renders `Guides/Show` too, so a Shop Cove carries the rail and gets its own
band: `sectionOf()` asks what kind the Cove is, never which controller method served it.

The tests assert **Inertia props, not rendered HTML**. A whole-page string search cannot tell a title
in the cards from the same title in the article above them — the mistake `GiftPersonaTest` records
having already made once with `assertDontSee`.

## Related

- [daily-cove.md](daily-cove.md) — the edition, and the archive strip this replaced
- [gift-personas.md](gift-personas.md) — the undated Coves, and what furniture they do not carry
- [all-coves.md](all-coves.md) — `/coves`, where the whole shelf is on show
- [seasonal-series.md](seasonal-series.md) — the seasons the series strip is for
- [giftability.md](giftability.md) — `giftable` against `worth_showing`
