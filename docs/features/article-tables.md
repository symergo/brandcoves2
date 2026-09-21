---
name: Tables inside an article
area: Content / Frontend
status: Active
date_added: 2026-09-21
---

# Tables inside an article

**A block of `| a | b |` lines in an article body renders as a table.**
`App\Services\Guides\CoveMarkup::tableFrom()` + `table()`, `ProseCards::blocks()`,
`resources/js/Pages/Guides/Show.tsx`.

## Why a second structure, after saying no to the first

`CoveMarkup` is not a Markdown parser and [says so at length](../../app/Services/Guides/CoveMarkup.php):
`#`, `_`, `[]()` and the rest stay unhandled because every one of them is a syntax a feed's product
title can contain by accident. Bold was added because the models were already writing it and it was
reaching the page as literal asterisks.

Tables are added on the same test, from the other end. Four articles listing the 2026 dates of about
sixty Christmas markets were published on 2026-09-21 as prose: one paragraph per country, each a run
of "city, market, dates" sentences. That is the correct shape for reading and the wrong one for
looking something up, which is what a dates page is for. The content was already a table; the
renderer was the only reason it was not one.

So the rule this file records: **a syntax is added when the writing is already producing it and the
absence is visible on the page.** Not because a parser would be tidier.

## The syntax, and why it is stricter than Markdown

```
| Stad | Markt | 2026 |
|---|---|---|
| Brussel | Winterpret | 27 nov - 3 jan |
```

Every line must open and close with a pipe, and the second line must be the rule (`|---|---|`).
Markdown lets both be sloppy. Here they are the whole defence against prose becoming a table by
accident: a sentence would have to begin *and* end with a pipe to qualify.

The failure being defended against is not a wrong style. It is **a paragraph silently rendered as a
one-column table** — nothing errors, nothing is reported, and the page simply reads wrongly. Hence
also the third requirement, at least one row: a header and a rule with no rows is a heading wearing
a border, and the writer meant a paragraph.

A **ragged row** is padded and truncated to the header's width instead of refusing the block. A
missing cell is a typo in one row; dropping the whole table over it would take sixty markets off the
page to punish one, and the reader would see no table and no reason.

## The pipe is two things at once

A cell is rendered by `render()`, so bold and every link token work inside one. That collides with
the delimiter: `[[search:kerstmarkt|markten]]` carries a pipe, and splitting the row on pipes cuts
the token in half — which is exactly what the first run of the tests did, producing two cells of
broken syntax.

`cells()` therefore hides the pipes inside `[[...]]` before the split and restores them after. A
literal pipe in cell *text* is still not supported and will not be: the alternative is an escaping
layer, and the writing that asked for tables has no pipes in it.

## A cell claims no product card

`ProseCards::claim()` pairs a product card to the paragraph that *discusses* a product, first
mention wins. A table is the exception: a cell naming `[[product:12]]` links, but claims nothing.

A cell is a reference, not writing. Claiming there would plant the card under the table and spend
the first mention on it, leaving the paragraph that actually argues for the product bare — the
"first mention wins" rule turned against the thing it exists to protect.

## Where it does not render

`CoveMarkup::paragraphs()` — the string-only path used by the digest email, `<meta>` descriptions,
FAQ answers, the legacy guide path and brand Cove bodies — **leaves a table block out**, exactly as
it leaves a figure out. A row of pipes in an email is worse than an absence.

It still counts the links inside the cells and reports their rejects, because `LinkCheck` reads that
same method: a link nothing checked is the link that breaks.

The Daily edition and persona pages render blocks as paragraphs only, so a table in *their*
editorial would be an empty paragraph. That is the same state figures have been in since 2026-09-12
and is left alone for the same reason: both are placed by a person in an article, and the builder is
never told they exist.

## The builder is not told about tables

`promptContract()` still says bold is the only markup that renders. Model-written prose therefore
never contains a table, and the only tables on the site are the ones a person wrote — the same
decision figures were given, for the same reason: an editorial structure a model reaches for
unprompted appears on pages nobody chose it for.

## On the page

The table scrolls inside its own box (`overflow-x-auto`, `min-w-[34rem]`), because a dates table has
four or five columns and a phone has none to spare — and a page that scrolls sideways as a whole
loses the reader's place in the prose above it.

## The deploy gate

Content with a table renders as literal pipes until this code is on the host serving it. **Deploy,
then publish the content** — the same order `cove-scenes.md` records for a new scene, and for the
same reason: the renderer is the thing that has to know first.

## Files

- `app/Services/Guides/CoveMarkup.php` — `TABLE_LINE`, `TABLE_RULE`, `tableFrom()`, `table()`, `cells()`
- `app/Services/Editorial/ProseCards.php` — the block, and why it claims nothing
- `resources/js/Pages/Guides/Show.tsx` — `Block.table`, and the scrolling box
- `tests/Unit/CoveMarkupTest.php`, `tests/Unit/ProseCardsTest.php`

## See also

- [cove-scenes.md](cove-scenes.md) — figures, the first block that is not a paragraph
- [editorial-api.md](editorial-api.md) — how an authored body reaches production
