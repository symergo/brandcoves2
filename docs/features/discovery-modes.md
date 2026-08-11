---
name: Discovery modes
area: Core / Discovery
status: Phase 1 active (Search · Guides · Serendipity)
date_added: 2026-08-08
---

# Discovery modes

**One retrieve → rank → present pipeline, reconfigured by a declarative Mode Profile.**

Not nine features. A mode is a config object — a retriever mix, five scoring numbers and a layout
name. Adding one is a row in `config/discovery.php` plus a layout branch in the frontend. If a new
mode ever requires editing `ModeEngine`, either the profile schema is missing a field or the thing is
not really a mode.

## The mental model

Modes live on a single axis: **how much intent the user has**, from pinpoint ("I know the exact
item") to none ("surprise me"). Two dimensions overlay *any* mode without being modes themselves:

- **modality** (text / voice / image) — changes how the query vector is produced, never which mode
  you are in. Voice is speech-to-text feeding the same `query` field; an image feeds the image
  retriever.
- **social** (solo / collaborative) — adds a collaborative retriever and blends its signal into
  ranking.

## The four stages

| Stage | What the profile controls |
|---|---|
| **Input → query representation** | `required_input`; `DiscoveryRequest` is one shape for every mode, and a retriever reads the fields it cares about |
| **Retrieve** | the weighted retriever mix |
| **Rank** | α, β, γ (the objective), λ (MMR), ε (exploration) |
| **Present** | `layout` — one `DiscoverySurface`, nine appearances |

### The objective

```
score = relevance^α · unexpectedness^β · novelty^γ · quality
```

**Multiplicative, not a weighted sum.** A candidate that fails on quality must not be able to buy its
way back with unexpectedness — that is the difference between a discovery engine and a junk feed, and
it is the [Serendipity Engine](serendipity.md)'s rarity × worth-seeing generalised to four terms.

An exponent of zero neutralises its term (x⁰ = 1). That is what makes the dial work with no
special-casing: Search sets β = γ = 0 and the objective collapses to `relevance^0.9 · quality`.
Asserted directly by `a_zero_exponent_neutralises_its_term` — two products whose surprise scores
differ by 89 points must score identically in Search mode.

Signals floor at 0.01 rather than 0. A single zero annihilates the whole product, so a candidate from
a retriever that does not set `novelty` would score zero in any mode with γ > 0. The floor makes a
missing signal cheap instead of fatal.

### Why you're seeing this

Every result carries the dominant scoring factor, measured on the **exponentiated** contribution, not
the raw signal. In Search mode β is zero, so a high unexpectedness contributes exactly nothing and
must not be reported as the reason. Comparing `α·log(relevance)` against `β·log(unexpectedness)`
compares like with like — the log of a product is the sum of the logs.

Required of every mode. A surface that visibly reorganises as a dial moves is incomprehensible
without it: the user has to be able to see that the same product is now here for a different reason.

## The dial

`ModeRegistry::atPosition()` finds the two stops either side of a position and **interpolates**: α
down, β/γ/ε up, retriever weights cross-faded, layout from whichever end is nearer (a layout cannot
be half of two things). Dragging Search → Serendipity therefore reorganises the same surface rather
than snapping between screens.

The user's own **surprise dial** applies on top, scaling β and γ by `0.5 + dial`, so 0.5 means "leave
the profile alone" — which is what a slider sitting in the middle should mean. Stored on
`users.surprise_dial` as nullable, so "never touched it" and "deliberately set it to the middle" stay
distinguishable.

Requests are debounced at 180 ms and superseded by request id: dragging a slider fires a burst, and
without the id the surface settles on whichever response landed last rather than the current
position.

## Retrievers

A shared library, mixed per mode. Every retriever must enforce the quality/giftability filter (not
optional per mode — a printer cartridge is wrong in every mode), degrade rather than throw, and set
**named signals** rather than a score. A retriever that scores makes the profile's α/β/γ meaningless.

| Key | Status | Notes |
|---|---|---|
| `keyword` | **built** | wraps the existing `SearchService`, so the `<%` word-similarity path and live bol folding are not reimplemented |
| `outlier` | **built** | reads `surprise_score`; lexical-rarity surrogate until pgvector |
| `curated` | **built** | daily editions + published guide shortlists |
| `fresh` | **built** | new arrivals *and* velocity — see below |
| `popular` | **built** | a retailer's bestseller chart. Rank sets `relevance`, week-on-week *movement* sets `novelty` — see [popularity-charts.md](popularity-charts.md) |
| `value` | **built** | measured against our own history and against other shops, never a merchant's "was" price |
| `spectrum` | **built** | an even sample across the price range, dupes marked |
| `slots` | **built** | curated goal→slots map; no AI in the request path |
| `twoTower` | **built, honestly scoped** | item-item co-occurrence over saved lists, not a trained model — see below |
| `semantic` · `image` | blocked on embeddings | Phase 8 |

### Three judgement calls worth recording

**`fresh` measures two things.** "New" is `first_seen_at`; "rising" is what share of a product's shops
arrived in the last fortnight. Newness alone would make this a feed-ingest changelog — one big
advertiser's first import floods it with ten thousand products that are new to *us* and years old in
the world.

**`popular` scores movement, not position.** A permanent number one is popular and is not *news*; a
product that went from #40 to #6 in a week is what "what's current" means. Rank feeds `relevance` and
week-on-week movement feeds `novelty`, so at Trends' γ = 0.7 against α = 0.3 the climber wins — which
is the entire reason a rank *history* is stored rather than a snapshot overwritten. `fresh` keeps 0.4
of the mix beside it, because a chart is one retailer's view and the whole Awin catalogue is invisible
to it.

**`value` never reads a merchant's reference price.** A large share of feed rows carry a "was" price
that was never charged. Ranking on it produces a page of 60%-off badges that are all fiction, and a
deals surface whose discounts are fiction is worth less than none — it teaches people not to trust
the numbers anywhere else on the site. Both measures are ours: against the 30-day median we recorded,
and against the same product at another shop right now. Savings under 8% score zero rather than a
small number, because a 3% "deal" on a deals page is a broken promise.

**`twoTower` is not a two-tower model, and is named for where one goes.** A learned user embedding
from three weeks of a new site's traffic is a confident-looking function of noise, indistinguishable
from a working one until it has been shaping results for months. What ships is item-item co-occurrence
over saved lists, requiring two distinct lists to agree before it will claim anything, counted
`DISTINCT` on the list so one enthusiastic user cannot steer the surface. When there is enough
interaction volume, this class is the seam.

**Weights renormalise over what is available.** Search declares `semantic: 0.2` and there is no
embedding index yet, so the mode runs on `keyword` alone and returns a full page rather than four
fifths of one. That property is what lets the whole axis be declared before every retriever exists —
tested by `a_profile_naming_an_unbuilt_retriever_degrades_onto_its_others`.

A profile naming an unregistered retriever logs a warning and continues. A typo in an editable config
row must not take the surface down.

## Guides are a mode, not a subsystem

A buying guide's shortlist **is** a curated pool. So the Guides mode is the same pipeline reading
`guide_items`, and a ghost-shop persona would be another pool feeding the same retriever — not
another codebase. This is why the `curated` retriever unions daily picks and guide items rather than
having one class each.

## Configuration

`config/discovery.php` declares all nine profiles, reviewed like code; `mode_profiles` rows override
individual fields without a redeploy. Every override column is nullable and nulls are stripped before
merging, so a row that changes only λ changes only λ rather than silently freezing everything else at
whatever the config said the day it was written.

Cached for a minute — read on every discovery request, changed roughly never, but short enough that
someone tuning a weight sees it without wondering whether they broke something.

## The learning loop

`discovery_reactions` logs `{mode, market, actor, group, reaction, dominant_factor, position}`.
Recording the factor is the half that matters: without it a row says "they disliked it" but not "they
disliked it for the reason we showed it", and it is the second that tunes a weight.

ε exists for the same reason. A purely greedy ranker never learns, because it never shows anything it
is unsure about, so nothing outside the current top slice ever collects a reaction.

## API

```
POST /{market}/discover
{ mode, dial?, surprise?, input:{query?|items?|goal?},
  context:{budget_min?, budget_max?}, exclude[], overlays:{modality, social} }
→ { items:[{…, reason, sources}], layout, modeMeta }

GET  /{market}/discover/{mode}   SSR landing, deep-linkable per mode
POST /{market}/discover/react    reaction → learning loop
```

### Assumptions flagged for correction

1. **Market prefix.** The spec's `POST /discover` is namespaced as `/{market}/discover` here. Market
   is a hard invariant of this codebase — identity, prices and language are all scoped to it — and an
   unprefixed route would be the one endpoint that has to resolve it some other way. Say the word and
   I will add the bare path as an alias resolving from `Accept-Language`.
2. **Offer Service.** The spec names a separate Redis-cached Offer Service. That role is already
   filled: `SearchService` folds live bol offers into the stored graph mid-request under a Redis
   token bucket, and `Product::outboundUrl()` handles click-out. I have wired the retriever to it
   rather than introducing a parallel service. Amazon prices are re-fetched at render, never
   mirrored — unchanged.
3. **Amazon Creators API.** The agreed scope defers Amazon to Phase 8 via PA-API. "Creators API" is a
   different product; if that is what you intend, it changes the connector, not the pipeline.
4. **Embeddings.** `semantic`, `image` and `twoTower` are declared and disabled. pgvector is Phase 8
   in the agreed plan. The `outlier` retriever approximates embedding-space oddness with lexical
   rarity — good enough to ship without a model or an index to keep in sync, and swapping it later is
   a change inside one class.
5. **Qdrant.** Not introduced. pgvector first; a second datastore is a large operational step and the
   catalogue is well within pgvector's range.

## Files

- `app/Services/Discover/` — `ModeEngine`, `ModeRegistry`, `ModeProfile`, `Ranker`, `Candidate`,
  `DiscoveryRequest`, `DiscoveryResult`, `Retrievers/`
- `config/discovery.php` — the nine declared profiles
- `app/Http/Controllers/DiscoverController.php`
- `resources/js/Pages/Discover.tsx`
- `tests/Feature/ModeEngineTest.php`

## Two modes are deliberately off

A disabled mode here is one that cannot yet do its job *honestly* — not one that would crash. Both of
the two currently off would return a perfectly plausible page, and that is exactly the problem.

- **`inspiration`** — 80% of its weight is `semantic` + `image`. With those unavailable it
  renormalises onto `curated` alone and answers "show me something calm and woody" with whatever was
  in last week's guide. A mood is a vector or it is nothing.
- **`advisor`** — the Gift Whisperer already *is* this mode and is better at it: a six-step brief with
  skippable questions, a reason per card, a per-card swap. Exposing a thinner version would put two
  different answers to the same question on one site, and the worse one would be the one with the
  dial on it. Turning it on means folding the wizard's brief into `DiscoveryRequest::$answers` and
  writing a retriever that reads them — real work, not a flag flip.

A plausible wrong answer costs more than a missing one.

## Ranking order is not reading order

`ModeProfile::$order` is a separate field because they are separate questions. The ranker decides
*which* results appear; Compare is a price ladder whose entire content is the ordering, so presenting
it by score scrambles the one thing the mode is for. `spectrum` also hands over exactly the requested
number of rungs rather than letting the engine over-fetch — over-fetching exists so MMR has choices,
and here that actively hurts: given four times the ladder, MMR discards the top as near-duplicates of
the bottom and returns the cheap end again.

## Two bugs the Phase 2 tests caught

**A zero-weight term won every explanation.** `dominant()` compared `β·log(unexpectedness)` against
the others; with β = 0 that term is exactly `0.0`, and every other contribution is negative (all
inputs ≤ 1). So Deals explained every result as "unexpectedness" *because* unexpectedness was doing
nothing. Zero-weight terms are now excluded. The Phase 1 test passed only by luck — its top result had
relevance ≈ 1.0, making that term 0.0 too, and a stable sort broke the tie the right way.

**Quality won the rest.** It is a gate, not a distinguishing factor: nearly every surviving candidate
scores 1.0, `log(1.0)` is 0, and it beat every real reason for the same arithmetic reason. It is also
a useless thing to tell someone — "well stocked and easy to compare" is true of everything on the
page, which is what makes it not an explanation. Excluded too.

## Phases

1. **Done.** ModeEngine with Search, Guides and Serendipity — the two endpoints of the axis plus the
   editorial stop — proving the shared pipeline and the dial on real Awin/bol data.
2. **Done.** Deals, Trends, Compare, Projects and Follow enabled; five new retrievers. No new
   pipeline, which was the claim Phase 1 existed to test.
3. Overlays (image/voice modality, social retriever), reaction-driven per-mode weight tuning, and the
   two modes above once embeddings land.
