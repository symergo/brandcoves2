# House style

Two punctuation habits that arrive with model-written prose, and where each one is dealt with.

- **Em dashes** become a spaced hyphen (`-`).
- **`**bold**`** renders as `<strong>` in prose, and has its asterisks removed from the fields that
  have no renderer.

Both are applied **at the write**, in `App\Services\Editorial\HouseStyle`, plus one renderer change
in `App\Services\Guides\CoveMarkup` and a backfill command for the archive.

---

## Why an em dash was worth removing

`—` is the mark a language model reaches for two or three times a paragraph. Nothing about it is
wrong; the *density* is the tell, and at the volume this site generates it is the single most legible
signal that nobody wrote the page. That matters commercially rather than aesthetically: these
articles exist to be trusted about what to buy.

The replacement is a spaced hyphen rather than a deletion because the clause on either side still
needs separating. Running two independent thoughts together changes what the sentence says, and there
is no reliable way to pick between a comma, a colon and a full stop without reading the sentence.
A spaced hyphen is what a person typing into a form produces, and it is right every time.

`word—word` is respaced to `word - word`, not collapsed to `word-word`, which would read as a
compound noun. A dash that opens a line gets no leading space.

**En dashes and hyphens are left alone.** `2020–2024` is a range and `Audio-Technica` is a name.
Neither is the habit this exists for.

## Why `**bold**` was reaching the page

`CoveMarkup` escapes prose and then walks it for `[[token]]` links, and nothing else — deliberately,
because handing model output to something that interprets markup is how a feed's stray angle bracket
becomes a tag. So Markdown emphasis arrived at the reader as literal asterisks.

The models write bold, and only bold: a sentence they want the reader to take away from a section of
advice. So `CoveMarkup::render()` gained one pattern for it, applied **after** the tokens have become
anchors — a bold run wrapping a link is the shape writers actually produce, and in that order the
only markup present when the pattern runs is markup the method itself emitted.

`#`, `_`, `[]()` and lists stay unhandled. Each is a syntax a feed's product title can contain by
accident, and a renderer that grows a rule per character ends up interpreting markup we did not
write. `CoveMarkup::promptContract()` now says so out loud, next to the link-token contract, so a
writer does not reach for a heading and get a literal `#`.

## The prose/plain split

This is the part that is easy to get backwards, and both directions are visible on the page.

| | Rendered by `CoveMarkup` | Printed as a React text node |
|---|---|---|
| **Fields** | `editorial`, `body`, FAQ answers, and `theme_blurb` on article kinds | titles, `verdict`, `daily_picks.blurb`, `meta_description`, FAQ questions, and `theme_blurb` on a Daily or persona |
| **Method** | `HouseStyle::prose()` | `HouseStyle::plain()` |
| **`**`** | kept — it becomes `<strong>` | removed — it would reach the reader as asterisks |

`theme_blurb` is the awkward one. It is a guide's opening paragraph *and* a Daily's standfirst, in
one column, because the fold (`2026_08_30_000100_a_guide_is_a_cove`) gave both kinds one table. Only
`GuideController` runs it through the renderer, and only for the article kinds — guide, seasonal,
advice and shop. `cove_plans.blurb` splits the same way, because it becomes `theme_blurb`.

`cove_plan_items.note` and `cove_plans.build_instructions` are deliberately untouched. They are an
editor briefing the builder, not copy, and no reader ever sees them.

## Where it is enforced

Every path that writes prose, because there is no single funnel:

| Writer | Applied in |
|---|---|
| Daily / persona editorial, and the generated theme | `EditionBuilder::write()`, `::theme()`, and the `planned` branch of `::editorial()` |
| Guide, seasonal, advice and shop articles | `GuideWriter::clean()`, which takes a `$prose` flag |
| The editorial API (Claude, through `giftcoves-seed-coves`) | `CoveQueueController::store()`, `CovePlanController` |
| The shipped articles | `AdviceCoveSeeder`, `SeedShopCovesCommand` |

Applying it at the write rather than at render is the decision worth recording. Six things read a
Cove's prose — the page, the digest email, the JSON-LD, the `<meta>` description, the admin table,
the export envelope — and filtering at render means all six remembering, with one of them eventually
not. Stored correct, they all agree for free.

**It is also stated in the prompts, and that is not redundant.** `Prompts\Defaults` carries the rule
in all seven system prompts, worded identically, so the substitution usually has nothing to do. It
cannot be *relied* on: those templates are editable from `/admin`, so a rewritten voice can take the
rule with it, and a model holding eight rules drops the one whose absence looks like nothing. The
prompt text itself was also de-dashed, because a prompt is the nearest thing the model has to an
example of the voice being asked for, and one that punctuates the way it is telling the writer not to
is an instruction arguing with a demonstration.

## The archive

```bash
php artisan bc:tidy-prose            # dry run: what would change, per table
php artisan bc:tidy-prose -v         # ... and which rows
php artisan bc:tidy-prose --write    # apply
php artisan bc:tidy-prose --market=be-nl --write
```

Walks `daily_pick_sets`, `daily_picks`, `cove_plans` and `cove_plan_items`. Tidying the plans is the
half that lasts: an edition is rebuilt from its plan routinely, so a pass over the published rows
alone would survive until the next build.

A command rather than a migration, for three reasons that all matter here. It has a dry run, so the
blast radius of rewriting text a person may have edited is visible first. It is **idempotent** — a
second pass finds nothing — so it is safe to run again after the next import. And it works against a
production database this repo never holds a copy of, where a migration would have to be right first
time against data nobody has looked at.

Only changed fields are written. A run over a tidy archive issues no `UPDATE` at all, which keeps
`updated_at` — what the admin table sorts by — meaning "when somebody last edited this".

The FAQ walk spreads each stored pair rather than rebuilding it: Postgres hands `jsonb` back with its
own key order, and a rebuilt `{q, a}` would look like a change on every run and rewrite every FAQ in
the archive every time.

The legacy `guides` and `guide_items` tables are skipped. Nothing has read them since the fold.

**Applied locally on 2026-08-31**: 91 fields across 45 Coves and 13 plans. `resources/content/`
(`advice-coves.php`, 153 dashes; `shop-coves.php`, 31) was de-dashed in the repo at the same time, so
a re-seed lands clean. Comments in those files were left as they are — they are code, not articles.
**Production has not been tidied**; run `bc:tidy-prose` there after the deploy.

## Related

- [prompt-bank.md](prompt-bank.md) — the editable half of what the model is told
- [product-cards-in-prose.md](product-cards-in-prose.md) — the link tokens `CoveMarkup` resolves
- [editorial-api.md](editorial-api.md) — how authored prose arrives
- [advice-coves.md](advice-coves.md) — the shipped articles this was most visible on
