---
name: The prompt bank
area: Content / Operations
status: Active
date_added: 2026-08-30
---

# The prompt bank

**What the writer is told is data, and there is a different one per Cove kind.**

Every prompt used to be a heredoc — the column's voice in `EditionBuilder`, the
guide's hard rules in `GuideBuilder`, the way a curator's note is introduced.
Changing the editorial voice was a code change and a redeploy, and the person with an
opinion about the voice is not the person with Coolify open. Exactly the argument
that produced the AI settings screen.

**Operations → Prompts** now holds an optional override per slot.

## The table is empty, and that is normal

A slot with **no row**, a **blank field**, a **disabled row** or an **unknown name**
all resolve to the prompt the application shipped with. `prompt_templates` can be
empty, half-filled or wrong and every build still produces exactly what it produced
before the table existed.

That fallback is the whole reason this is safe to hand over: an editor cannot break a
Cove by deleting a row.

> Page copy used to give the same guarantee and deliberately no longer does: a region with no blocks
> renders nothing, because fixed system text was the thing page templates replaced. A prompt is not
> copy — a blank one produces no article at all rather than a shorter one — so the floor stays here.
> See [page-templates.md](page-templates.md).

Clearing a field means "back to the shipped prompt", not "send the model an empty
system message" — the same convention as `AiSettingsStore`, where a null value
deletes the row rather than storing null. Deleting *is* the undo; there is no third
state between "the shipped prompt" and "mine".

## Slots

One per Cove kind, plus the theme call: `cove.daily`, `cove.persona`, `cove.guide`,
`cove.seasonal`, `cove.advice`, `cove.theme`. Derived from `CoveKind::cases()`, so a
sixth kind does not need remembering in two places.

The list lives in code. A row for a slot that no longer exists is **inert** rather
than a way to reach something it should not — the same reasoning as
`AiSettingsStore::KEYS`, and the reason the column carries no CHECK constraint.

### Every kind has its own, and two pairings were causing real failures

A Daily and a persona shared one prompt; a buying guide and a seasonal one shared
another. Neither pairing produced a vague loss of quality. Each produced a specific,
repeatable mistake:

**A persona told it is a daily column writes about today.** "This week we have been
looking at…" on a page that is undated, evergreen, about a *recipient* rather than a
date, and read in March and again in November. The persona prompt therefore names the
tense as a rule — never "today", "this week", "right now", "just in", "this year" —
because a model handed a list of products naturally narrates the moment it was handed
them.

**A seasonal guide told it is a buying guide writes as though the season has
started.** Seasonal Coves are commissioned months ahead on purpose: the search log
cannot see a season coming, so a barbecue guide mined from June's log first earns
traffic the following May. "With Halloween almost here" gets written in July and is
wrong for eleven months of the year. Its prompt says: name the season, never date the
reader — and it is given `{season}`, the window itself, so it can be specific without
being timely.

**And it now names the title as a thing to be written, because it was the one output
nobody had briefed.** The model has always returned a `title` — it is in
`GuideWriter`'s schema hint — but the seasonal system prompt asked for "three things"
and listed the intro, the how-to-choose and the entries. Given no guidance and a
`{title}` in the *input* that is only the topic word (`TopicPlanner` seeds a plan's
title as `Str::ucfirst($topic->topic)`, so "barbecue"), the model reached for the
formula every competitor already uses: "the best barbecues". A title that describes
the page's *format* is interchangeable with every other page on the subject, which is
the one thing a page competing on a known season cannot afford.

The rules that follow are all consequences rather than taste. No "the best", "top 10",
"the ultimate" or a leading number. Keep the subject recognisable, because clever and
unidentifiable loses the scan of a results page — a real tension, since `focus_keyphrase`
is a separate field and it is tempting to let the title go fully abstract once the
keyphrase is safe. Under ten words, no stacked subtitle. And no year and no "this
season", which is the seasonal rule the rest of the prompt already lives by: a title
that dates is a title that expires, on a page written to be read eight weeks early, on
the day, and again next year.

> **The AI-off fallback still says "the best".** `GuideWriter::template()` titles from
> `site.guides.template_title` — "De beste :topic" / "The best :topic" — and that slot
> is shared with ordinary buying guides in four languages. It only appears when
> `AI_ENABLED=false` or the call fails, and on a seasonal topic it also reads badly
> ("De beste schoonmaken"). Changing it is a separate decision because it is not
> seasonal-only.

The other three differ for smaller reasons. A **Daily** is one morning's edition and
must not refer to yesterday's or promise tomorrow's, because it is mostly read later
from the archive. A **guide** is a comparison, so "best for X" is required and "the
best" is forbidden — the reader's situation is the thing the writer does not know. An
**advice** article has no shortlist, so it gets its own rules entirely: a model handed
"two sentences per item, maximum" with no items will invent some to write them about.

What is deliberately **identical** across all of them is the three rules that protect
the reader — only the products listed, never a price, never an invented claim —
phrased the same way every time, because a model reads a re-phrased rule as a
different rule.

## Two halves

The **system** prompt is the rules and the voice. The **user** prompt is the brief:
what this particular page is about and which products are on it.

They are independently overridable because they are different edits. Somebody who
wants a drier voice touches the system half; somebody reordering what the model is
told touches the user half. Requiring both to be rewritten together would make the
small change carry the risk of the large one.

## The brief is composed from named blocks

The user prompt is a template with placeholders, and the writer supplies pre-rendered
blocks. **Which blocks exist depends on the slot**, because each kind's brief carries
different facts:

| Slot | Blocks |
|---|---|
| `cove.daily` | `language` `title` `occasion` `direction` `curated` `finds` |
| `cove.persona` | `language` `title` `direction` `curated` `finds` |
| `cove.guide` | `language` `topic` `title` `direction` `curated` `finds` |
| `cove.seasonal` | …and `season` |
| `cove.advice` | `language` `topic` `title` `direction` |
| `cove.theme` | `language` `finds` `recent` |

Offering a placeholder the writer never binds is worse than not offering it: it
renders as nothing, so the template looks right and quietly drops a line.
`{occasion}` is the clearest case — a Daily has one, a persona is undated and can
never have one, and a persona template referring to it would be blank forever.

Blocks rather than a template language, because a template language that can loop is
a program, and a program in a settings screen is a program nobody reviews.

An empty block leaves **no gap**: the placeholder and the blank line around it are
removed together. Three blank lines where a shortlist would be is a prompt that reads
as though something failed to render — which is what a model concludes too.

### Two placeholders are required, and this is the point

`{language}` and `{finds}` are enforced when the template is saved. A template that
has lost its product block asks the model to write about nothing, and **a model asked
to write about nothing writes a plausible article about products that are not on the
page.** It reads fine and is entirely invented.

`cove.advice` requires only `{language}`: it has no shortlist by definition, so
requiring a product block there would be a rule that exists purely to be inert.

An unknown placeholder is rejected too, naming it, because `{merchnat}` renders as
nothing and the failure is silent.

## What cannot be edited, and why the screen says so

Two things stay in code and are appended after whatever is written here:

1. **`CoveMarkup::promptContract()`** — the link-token contract and this article's
   product/brand allowlist. An edited system prompt that dropped it would stop every
   `[[product:…]]` being produced, and the only symptom would be articles quietly
   losing their internal links on a site whose whole argument is comparison.
2. **The curated-versus-uncurated flip** of the column's last rule. On an
   engine-picked edition "pick two or three worth a sentence" is right; on a curated
   one it is exactly wrong, because somebody chose those products and wrote down why.
   That is derived from the plan in front of the builder, not from a setting.

## Precedence

Shipped default → this table → the plan's `build_instructions`.

The per-plan direction still goes in the **user** prompt, underneath the system
rules, so "mention how cheap it is" cannot become permission to. A plan can change
the angle; it cannot overturn a house rule.

## Deliberately not seeded

`bc:seed-copy` has a documented trap: a seeded slot shadows the language file, so a
later rewrite of the shipped copy becomes invisible. The trap is worse here, because
a stale prompt produces plausible output rather than obviously missing text. There is
no `bc:seed-prompts`, and a row exists only when somebody actually wrote one.

## Safety

Editing a prompt cannot enable AI, raise a cap, or let a request spend money.
`AiClient` is reachable only from a queued job — invariant 1, enforced by an
architecture test — and nothing here touches that. Prompt text is admin-authored and
never rendered to a visitor, and model output still passes through `CoveMarkup`'s
escape-then-allowlist rendering, so an edited prompt cannot inject markup into a page.

## Files

- `app/Services/Ai/PromptBank.php` — resolution, fallback, placeholder validation
- `app/Models/PromptTemplate.php` — flushes the cache on save, wherever the edit came from
- `app/Filament/Resources/PromptTemplates/`
- `tests/Feature/PromptBankTest.php`

## Open

- `WidenGiftAngles` and `TriageCommunityPost` still hold their prompts in code. They
  are not editorial voice, so they were left out rather than given a slot nobody
  would use.
- No diff against the shipped default on the edit screen; you can see *that* a slot
  is overridden, not what changed.

## See also

- [ai-invariant.md](ai-invariant.md) — why AI is only ever called from a job
- [cove-planner.md](cove-planner.md) — where `build_instructions` comes from
