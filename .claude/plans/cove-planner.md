# One planner, one editorial table

## Context

Today GiftCoves plans and publishes editorial through two unrelated pipelines.

**Coves are planned.** `cove_plans` holds the intention — title, blurb, steering
queries, a curated shortlist in `cove_plan_items` with a per-product note, a
`pick_mode`, and `build_instructions` for the writer. An editor curates on
`/admin/cove-plans/{id}/curate`, approves, and `EditionBuilder` publishes a
`daily_pick_sets` row. Two kinds exist: a dated Daily Cove and an undated gift
persona.

**Guides are not planned at all.** `TopicMiner` clusters the search log and
`SeasonalTopics` seeds 24 seasonal windows into `guide_topics`; an editor queues
or rejects a topic, and then `GuideBuilder` chooses the products *itself* — a
brand-diverse price ladder — writes the prose and publishes a `guides` row.
There is no shortlist to curate, nowhere to say why a product is on the list,
and no way to brief the writer. The only human control is editing prose
afterwards.

So the two halves of one job — decide what a page is about, choose the products,
brief the writer, publish — work in two different ways, and only one of them
lets a person do the deciding.

**Wanted:** one **Cove planner** that plans every kind of Cove — daily, gift
persona, seasonal guide, buying guide, advice article — through the same
curate → brief → approve → build flow; and one **Cove editorials** page that
filters, edits and manages everything published, whatever its kind.

Two decisions are already taken and this plan assumes them:

1. **Guides fold into editions.** `guides` and `guide_items` retire; everything
   published is a `daily_pick_sets` / `daily_picks` row carrying a kind.
   `/{market}/guides` and `/{market}/guides/{slug}` keep working exactly as they
   do — same URLs, sitemap entries, hreflang pairs, and `magazine`/`articles`
   legacy redirects.
2. **Every build mints a plan.** The 06:00 automatic build and the topic queue
   write a plan before publishing, and a backfill mints one per existing row, so
   `cove_plans` becomes a complete record of what was published and why.

On top of that, a Cove can be **redone** — its products reselected and its
article rewritten from nothing — without losing its URL. That is a different
operation from the idempotent rebuild that exists today, and Phase 3b covers it.

Separately, and independent of the planner: **the prompts that instruct the
writer become editable in the admin, with a different system and user prompt per
Cove kind.** Today every prompt is a heredoc inside `EditionBuilder`,
`GuideBuilder` and two jobs, so changing the editorial voice is a code change and
a redeploy. Phase 7 covers it.

And **the editorial API gains a writing queue**, so a Claude scheduled agent can
ask what needs writing, write it, and post the prose back as a draft for a person
to approve — filling the Cove database without ever spending AI budget on this
server. Phase 8 covers it, including the runbook and the prompt to give the
scheduler.

This is a large change across ~40 files, two public URL spaces and an external
API. It is sequenced into phases, each independently shippable and green.

---

## Phase 1 — the schema fold (expand only)

New migration `2026_08_30_000100_a_guide_is_a_cove.php`.

**`daily_pick_sets` gains** (guide columns that have no edition equivalent):

| Column | Type | From |
|---|---|---|
| `body` | text null | `guides.body_md` — named `body`, never `_md`: it is rendered as plain paragraphs, not Markdown |
| `faq` | jsonb null | `guides.faq` |
| `meta_description` | string null | `guides.meta_description` |
| `focus_keyphrase` | string null | `guides.focus_keyphrase` |
| `source_queries` | jsonb default `'[]'` | `guides.source_queries` |
| `source_volume` | integer default 0 | `guides.source_volume` |
| `last_checked_at` | timestamptz null | `guides.last_checked_at` |
| `season_from` / `season_to` | varchar(5) null | `guide_topics.season_*`, `MM-DD`, windows may wrap the year |
| `featured_cove_id` | FK `daily_pick_sets` nullOnDelete | replaces `guide_id`, now a self-reference |

Reused as-is, no widening needed (verified): `theme_title` ← `title`,
`theme_blurb` (already `text`) ← `intro`, `slug`, `market`, `status`
(`PublishStatus`), `published_at`, `editorial`/`editorial_source`.

**`daily_picks` gains** `verdict` (string null) and `unavailable` (bool default
false). `blurb` already matches `guide_items.editorial_copy`. Guides *dim* an
unavailable item where the Daily *hides* it — that difference is real editorial
behaviour and `unavailable` is what carries it.

**Constraints:**

- `daily_pick_sets_kind_check` → `kind IN ('daily','persona','guide','seasonal','advice')`
- `daily_pick_sets_address_check` → `(kind = 'daily' AND drop_date IS NOT NULL AND slug IS NULL) OR (kind <> 'daily' AND drop_date IS NULL AND slug IS NOT NULL)`
- Both partial unique indexes stay as they are. Note the consequence and
  document it: a persona and a guide in one market **cannot share a slug**, even
  though they live at different paths. One slug namespace per market is the
  simpler rule and it keeps `[[guide:slug]]` unambiguous.

`cove_plans` gets the same treatment in the same migration: kind CHECK extended
to the five values; `season_from`/`season_to`, `body`, `meta_description`,
`focus_keyphrase`, `faq`; and the persona CHECK generalises from
`kind <> 'persona' OR drop_date IS NULL` to `kind = 'daily' OR drop_date IS NULL`.

**Backfill, in this order, inside the migration:**

1. `guides` → `daily_pick_sets`. Kind resolves as
   `CASE WHEN t.origin = 'seasonal' THEN 'seasonal' WHEN g.kind = 'advice' THEN 'advice' ELSE 'guide' END`
   (left-joining `guide_topics t ON t.guide_id = g.id`), so a guide that exists
   because of a season is planned as one from now on. `theme_source` is set to
   `'imported'`. **Slug collisions with an existing persona must be resolved by
   suffixing, never by dropping the row** — a silent `ON CONFLICT DO NOTHING`
   here loses a published page.
2. `guide_items` → `daily_picks`, joined through a `(guide_id → set_id)` map
   recovered on `(market, slug)`. `editorial_copy → blurb`, `verdict`,
   `unavailable`, `rank` preserved; `slug` derived from the product group;
   `surprise_score` left null (guides never had one).
3. `daily_pick_sets.guide_id` → `featured_cove_id` through the same map.
4. `guide_topics` gains `edition_id` (FK `daily_pick_sets`) and `plan_id` (FK
   `cove_plans`), backfilled from `guide_id`.

**Contract later** (Phase 6, once nothing reads them): drop `guides`,
`guide_items`, `daily_pick_sets.guide_id`, `guide_topics.guide_id`, and
`cove_plans.pinned_group_ids` (already superseded by `cove_plan_items`).

Verification for this phase is a migration test asserting row counts and that no
guide's `intro`, `body_md`, per-item `editorial_copy` or `verdict` is null after
the fold where it was non-null before.

---

## Phase 2 — kinds, and a plan behind every build

**`App\Enums\CoveKind`** gains `Guide`, `Seasonal`, `Advice`. It grows from a
label-holder into the place the per-kind rules live:

```
isDated(), label(), urlPath(string $slug), minimumItems(), targetItems(),
aiFeature(), isArticle()   // guide|seasonal|advice — the /guides URL space
```

`urlPath()` is what removes the kind branching scattered through controllers,
the sitemap, `ScheduleConflicts`, `CovePlanController::summary()` and the two
Filament resources.

**Plan-per-build.** `EditionBuilder::build()` reads `CovePlan::approvedFor()`
and proceeds without one when there is none. Add
`CovePlan::forBuild(Market, CoveKind, ?date, ?slug): CovePlan` — returns the
existing plan in *any* status, or mints one with `status = 'used'`. Minting must
be an upsert on the same keys as the partial unique indexes, or a rebuild raises
a constraint violation at 06:00. `approvedFor()` is left exactly as it is: it
answers "should this plan drive the build", and a minted `used` plan must never
be picked up as an editorial decision on the next run.

A second migration mints a plan per existing edition — `status = 'used'`,
`edition_id` set, kind copied, title/blurb from `theme_*`, items from `picks`
(preserving rank, `blurb → note` is *wrong* and must not be done: the pick blurb
is output, the item note is input, so notes stay null).

---

## Phase 3 — one builder, per-kind strategy

`EditionBuilder` is 1044 lines and `GuideBuilder` is 420; merging them naively
produces something nobody can read. Introduce a per-kind profile instead, in
`app/Services/Cove/`:

- **`CoveProfile`** — resolved from a `CoveKind`. Carries `minimumItems()`,
  `targetItems()`, `aiFeature()`, and returns the two collaborators below.
- **`Selectors\SurpriseSelector`** — today's `EditionBuilder::finds()` lanes:
  curated lead, themed matches, surprise-scored rest, `spread()` for category
  variety, repeat memory. Used by `daily` and `persona`.
- **`Selectors\LadderSelector`** — today's `GuideBuilder::shortlist()`: one
  tsquery over `products.search_vector`, ordered `merchant_count DESC` then
  `word_similarity`, one group per brand, then sorted by `min_price` ascending
  so the guide reads as a price ladder. Used by `guide` and `seasonal`.
- **`Writers\ColumnWriter`** — the 2–3-paragraph editorial (`daily_picks` cap).
- **`Writers\GuideWriter`** — title / intro / how-to-choose / FAQ / per-item
  verdict+copy (`guide_copy` cap). **It gains `CoveMarkup::promptContract()`,
  which it does not have today** — that omission is why guide prose has no link
  tokens while Cove prose does.

`EditionBuilder` then shrinks to orchestration: resolve plan → profile →
curated items lead → selector fills unless `pick_mode` is `locked` → minimum
check → writer → upsert set + `writePicks()`. Three behaviours become uniform
across every kind for the first time, which is the point of the whole change:
the curated shortlist leads, `pick_mode` is honoured, and `build_instructions`
reaches the writer. `plan->editorial` keeps short-circuiting the model entirely.

`GuideBuilder::refreshCopy()` moves to `EditionBuilder::refreshCopy(DailyPickSet)`
keeping both of its invariants intact: the shortlist is never re-chosen, and AI
copy is never traded back for the template.

`candidates()` — the curation screen's suggestion engine — delegates to the
profile's selector, so a guide plan is suggested a price ladder and a daily is
suggested surprises. It keeps the trick of cloning the plan with `pick_mode`
forced to `open`.

`BuildDailyEdition` and `BuildPersonaCove` collapse into one
`App\Jobs\BuildCove(int $planId)`. `BuildDailyEdition(Market)` stays as the
scheduled entry point that mines topics, seeds seasonal ones, mints today's plan
and dispatches `BuildCove` — the schedule in `routes/console.php` is unchanged.

Config: register the per-kind feature keys already in
`config/giftcoves.php` `ai.caps` (`daily_picks`, `guide_copy`) against the
kinds, and give `giftcoves.guides.min_products` / `items_per_guide` real readers
at last — today the builder uses hard-coded 5 and 7 while config says 6 and 8.
Pick one set of numbers deliberately and comment why.

---

## Phase 3b — redoing a Cove

**Redo means: reselect the products and rewrite the article from nothing, at the
same URL.** It is not the rebuild that exists today. A rebuild is *idempotent* —
`updateOrCreate` on the address plus a delete-and-reinsert of picks — so it
reproduces the same page from the same inputs. A redo deliberately throws the
inputs away.

Four things stop today's rebuild from doing this, and each needs an answer:

1. **A curated shortlist survives a rebuild** — that is the whole point of
   `cove_plan_items`. A redo has to clear it, or re-seed it from the engine.
2. **`plan->editorial` short-circuits the model entirely**
   (`EditionBuilder::editorial()`, `filled($plan->editorial)` → `source:
   'planned'`). Authored prose left in place means "rewrite" silently does
   nothing.
3. **Reselection is not automatically different.** The daily's `finds()` excludes
   products used within `picks.memory_days`, so a redone Daily naturally lands on
   new products. A persona or a guide is not in that memory, and
   `LadderSelector` is deterministic — a redo would return the *identical*
   ladder. So redo must pass the edition's current `group_id`s as an explicit
   exclusion, which is the mechanic that makes it mean anything.
4. **`published_at` must not move.** A persona stamps it once on purpose; a guide
   build sets it to `now()`. A redo that bumps it re-dates a page that has been
   live for months and reshuffles every "newest first" shelf on the site.

**Implementation.** `EditionBuilder::redo(CovePlan $plan, RedoOptions $options)`,
running inside the same transaction as a normal build:

- carries `excludeGroupIds` (the current picks) into the profile's selector;
- keeps `id`, `market`, `kind`, `slug` / `drop_date`, `published_at`,
  `created_at` — so the URL, the canonical, the sitemap entry and the hreflang
  pairs are all untouched;
- resets `editorial`, `editorial_source`, `body`, `faq`, `theme_blurb`,
  `last_checked_at`, and every pick;
- keeps `theme_title` unless the plan itself is retitled — the title is the
  editorial decision the plan records, not build output;
- for a Daily, does **not** re-claim `used_themes`, which is already recorded.

`RedoOptions` carries the two choices a curator actually has, both offered in the
confirmation dialog:

| Option | Effect |
|---|---|
| *Reselect everything* | clears `cove_plan_items` and lets the engine choose afresh, excluding what is there now |
| *Keep my shortlist, rewrite the words* | items survive; only the prose is discarded. Distinct from `refreshCopy()`, which re-writes but keeps the existing copy on failure |

Both clear `plan->editorial` first, or the model is never called.

**What a redo destroys, and must say so before it runs.** Deleting the picks
cascades `pick_reactions` — a redone Cove loses every reader reaction it had
collected, and there is no undo. The confirmation modal names that outcome
explicitly, alongside "the URL stays, the page changes". For a **published**
Cove it also warns that readers and search engines have already seen the old
version at that address.

Surfaces: a **Redo** row action on Cove editorials and a header action on the
curate screen (both `requiresConfirmation()` with the modal above), plus
`bc:redo-cove {market} {--date=|--slug=} {--keep-items}` for the operational
path. The editorial API gets no redo verb — an API client already achieves this
by rewriting the plan and re-approving it.

---

## Phase 4 — the two admin pages

**`CovePlanResource` → "Cove planner"** (`app/Filament/Resources/CovePlans/`).
Navigation label and model label change; the form becomes kind-conditional:

- `daily` → date picker (unchanged)
- `persona` / `guide` / `seasonal` / `advice` → permanent slug
- `seasonal` → season window (`season_from` / `season_to`, `MM-DD`)
- `guide` / `seasonal` / `advice` → focus keyphrase, meta description, a FAQ
  repeater, and the `body` ("how to choose") textarea

**`CuratePlan`** (`Pages/CuratePlan.php`, 604 lines + a 404-line Blade view) is
already almost kind-agnostic — search, add, remove, reorder, notes, pick mode,
instructions and conflicts all work unchanged, and the Blade header already
renders `$plan->kind->label()` with date-or-slug. Three edits:

1. `summary()` and `warning()` read `config('giftcoves.picks.*')` directly;
   they must read the profile's `minimumItems()`/`targetItems()` instead, or a
   buying guide is judged against the Daily's floor.
2. `suggest()` likewise, so the suggestion count matches the kind.
3. The build dispatch at `CuratePlan.php:541` — the page's only kind branch —
   becomes `BuildCove::dispatch($plan->id)`.

**New `CoveEditorialResource`** over `DailyPickSet`, navigation "Cove
editorials", **replacing both** the "Daily Cove" and "Guides" nav entries:

- `ListCoveEditorials::getTabs()` — All / Daily / Personas / Guides / Seasonal /
  Advice, each a `kind` scope.
- Filters: market, status, kind, date range; search on `theme_title`.
- An edit page carrying the prose — `theme_title`, `theme_blurb`, `editorial`,
  `body`, `faq`, `meta_description` — because guides are editable today and that
  must not regress; plus a picks relation manager for per-pick `blurb`,
  `verdict` and `unavailable`.
- Row actions ported from `GuideResource`: **view** (via `CoveKind::urlPath()`),
  **Copy preview link** (`PreviewAccess::link`, drafts only), **unpublish**
  (draft, keep the slug), **rebuild**, **redo** (Phase 3b), and **open its
  plan**. Rebuild and redo sit next to each other and must read as different
  things: rebuild reproduces the page, redo replaces it.

`DailyEditionResource` and `GuideResource` are deleted; their useful actions
move here.

**`GuideTopicResource`** ("Cove topics") stops being a publish queue. Its
`queue` action becomes **"Draft a plan"**: mint a `CovePlan` of kind `guide` or
`seasonal` from the topic — title, `queries` from `member_queries`, slug, season
window — prefilled with the ladder shortlist via `PlanCurator::prefill()`, and
link `guide_topics.plan_id`. `reject` is unchanged. This is what makes the topic
queue an *idea feed* rather than a second publishing pipeline.

---

## Phase 5 — everything downstream

| File | Change |
|---|---|
| `Http/Controllers/GuideController.php` | reads `DailyPickSet` where `kind` is an article kind; URLs, robots rules, `StructuredData::itemList`/`faq`/`breadcrumbs` and the dim-not-hide rule all unchanged |
| `Http/Controllers/{DailyCove,GiftIdeas}Controller.php` | `scopeDaily()` / `scopePersonas()` still apply — see the risk note below |
| `Services/Cove/EditionPresenter.php` | gains an article presentation (intro + body + FAQ + per-item verdict) beside the existing `editorial()`/`finds()`/`guide()`. Guide items were always catalogue groups; an edition pick may be an Amazon decision (`amazon_asin`), so the article presenter must handle a live-only pick or a folded guide silently drops it — invariant 6 |
| `Http/Controllers/{Home,Brand,DiscoverCove}Controller.php` | swap `Guide::query()` for a `DailyPickSet` article scope; `BrandController` searches `body_md` for `[[brand:…]]` tokens → now `body` |
| `Services/Editorial/Allowlist.php` | `guideSlugs()` reads editions |
| `Http/Controllers/{Sitemap,OgImage}Controller.php`, `Services/Seo/Alternates.php` | same queries against editions; **URLs must not change** |
| `Services/Seo/LegacyRedirects.php` | unchanged — `magazine`/`articles` → `guides` still resolves |
| `Console/Commands/RefreshGuideCopyCommand.php` | `bc:refresh-cove-copy`, keeping the old name as an alias so the 04:40 schedule and any runbook keep working |
| `Http/Controllers/Api/GuideEditorialController.php` | **keeps its routes and its payload shape** (`POST /guides`, `POST /guides/{guide}/publish`, `GuideKind::minimumItems()` rules) and writes editions underneath. The documented API is a contract with an external writer; breaking it is not in scope |
| `Services/Content/ContentEnvelope.php` | `SURFACES` loses `guides`; `editions` carries everything. Bump `VERSION` to 2 and keep reading a v1 envelope's `guides` surface by mapping it into editions |
| `Models/Guide.php`, `Models/GuideItem.php` | deleted in Phase 6 with the tables |

---

## Phase 6 — contract, tests, docs

**Contract migration** drops `guides`, `guide_items`, `daily_pick_sets.guide_id`,
`guide_topics.guide_id`, `cove_plans.pinned_group_ids`.

**Tests that must change** — `CovePlanCurationTest` (497), `CuratePlanScreenTest`
(354), `GiftPersonaTest`, `DailyCoveTest`, `EditorialApiTest` (769),
`ContentPromotionTest`, `GuideCopyRefreshTest`, `SeasonalCoveTest`,
`PreviewTest`, `SeoTest`, `OgImageTest`, `AdminPanelTest`, `PageSmokeTest`,
`DiscoverCoveHubTest`.

**New tests pinning the risky parts:**

1. **No URL regressions.** Every `/{market}/guides/{slug}` live before the fold
   answers 200 after it, with the same title, intro, body, FAQ and item order.
2. **The NULLS-FIRST trap, widened.** `ORDER BY drop_date DESC` is NULLS FIRST
   in Postgres, and the existing `scopeDaily()` exists because one dateless
   persona would otherwise be served as today's edition. After this change
   **four of five kinds are dateless**, so the same bug has four new ways to
   appear. Pin every surface: `/daily`, `/`, the archive strip, the digest, the
   sitemap, `DiscoverCove`, OG images.
3. **No prose lost in the backfill** — the assertion described in Phase 1.
4. **Curation is uniform.** A locked guide plan publishes exactly its shortlist
   in curator order; `build_instructions` reaches the guide writer; authored
   `editorial` on a guide plan skips the model at zero AI cost.
5. **Slug namespace.** A guide cannot take a persona's slug in the same market.
6. **Reactions stay off articles.** `daily_picks` carries `mindblown_count` /
   `meh_count` and a `POST /picks/{pick}/react` route. Folding guide items into
   that table makes every guide product reactable at the API level for the first
   time; personas already deliberately have none. Pin that an article pick
   refuses a reaction rather than quietly accepting one.
7. **AI spend stays capped and queued.** Invariant 1: a "Build now" on a guide
   plan spends `guide_copy` from a queued job, never from the admin request.
8. **Redo keeps the address and changes the page.** New `CoveRedoTest`: the slug,
   `drop_date`, `published_at`, `created_at` and edition id all survive; the URL
   still answers 200; the picks are different products; the prose is
   regenerated; authored `plan->editorial` is cleared rather than reused; a
   guide's deterministic ladder returns a *different* shortlist because the
   current picks were excluded; `used_themes` is not double-claimed; and
   "keep my shortlist" leaves `cove_plan_items` intact while still rewriting.

**Docs** (the `docs/features/` rule in CLAUDE.md is not optional here): a new
`cove-planner.md` as the primary document; substantial rewrites of
`daily-cove.md`, `cove-curation.md`, `gift-personas.md`, `editorial-api.md`,
`content-promotion.md`; a row in `INDEX.md`; and a note in `navigation.md` for
the two replaced admin entries. The *why* to record: why guides folded into
editions rather than staying a second table, why one slug namespace per market,
why a minted plan is `used` and not `approved`, and why redo excludes the
current picks — without that exclusion a redone guide silently returns the
identical products, which is the failure the feature exists to prevent.

---

## Phase 7 — editable prompts, one per Cove kind

**Independent of the rest.** It needs `CoveKind`'s new values from Phase 2 for
its full slot list, but it can ship before the fold with the two kinds that
exist today.

Every prompt in the application is a heredoc: `EditionBuilder::editorialSystem()`
(`:738`) and `editorialPrompt()` (`:779`), the theme call at `:948`,
`GuideBuilder::system()` (`:320`) and `prompt()` (`:341`), and
`WidenGiftAngles::system()`/`prompt()`. Changing the editorial voice is a
redeploy, and the person with an opinion about the voice is not the person with
Coolify open — the same argument that produced the AI settings page.

This deliberately mirrors the **copy bank** (`CopyTemplate` + `CopyBank` +
`bc:seed-copy`), which already solved this exact shape for page copy.

**Storage — `prompt_templates`:** `id`, `slot` (unique), `system` (text null),
`user_template` (text null), `enabled` (bool default true), `notes`, `author_id`,
timestamps. No CHECK on `slot`; the allowlist lives in code, so a row for an
unknown slot is inert rather than a way to reach something it should not — the
same guard `AiSettingsStore::KEYS` provides.

**Slots** — one per Cove kind, plus the non-Cove callers:
`cove.daily`, `cove.persona`, `cove.seasonal`, `cove.guide`, `cove.advice`,
`cove.theme`, `gift.angles`, `community.triage`. Each holds both halves.

**`App\Services\Ai\PromptBank`** — `system(string $slot)` and
`user(string $slot, array $bindings)`. The shipped heredocs move verbatim into
`App\Services\Ai\Prompts\Defaults`, and **no row, a blank row, a disabled row or
an unknown slot all resolve to the shipped default**. The table can be empty,
half-filled or wrong and every build still produces exactly what it produces
today. Cached for an hour, flushed on save, like `AiSettingsStore`.

**Placeholders are validated on save.** The user prompt is assembled from data,
so an editable template needs named bindings — `{language}`, `{title}`,
`{occasion}`, `{direction}`, `{curated}`, `{finds}`, `{topic}`, `{shortlist}` —
and two rules enforced when the form is submitted:

- an **unknown** placeholder is rejected, naming it;
- a **required** placeholder that has been deleted is rejected. `{finds}` (or
  `{shortlist}`) and `{language}` are required, because a template without them
  asks the model to write about nothing, in no particular language, and the
  result is a plausible article about products that are not on the page.

This validation is the most valuable thing on the screen. The form lists the
slot's available placeholders with one line each.

**Two things stay in code and are appended after the editable system text**, and
the page says so, or it looks like the whole prompt is there:

1. `CoveMarkup::promptContract($allowed)` — the link-token contract and the
   article's product/brand allowlist. If an editor could delete it, every
   `[[product:…]]` stops being produced and articles silently lose their
   internal links. (Phase 3b/3 also gives this to the guide writer, which lacks
   it today.)
2. The curated-versus-uncurated flip of the last system rule
   (`editorialSystem(bool $curated)`) — that is derived from the plan in front of
   the builder, not from a setting.

**Precedence: shipped default → prompt template → the plan's
`build_instructions`.** The per-plan direction keeps going into the *user*
prompt beneath the system rules exactly as it does today (`EditionBuilder:818`),
so a per-plan instruction still cannot override a house rule.

**Admin — `PromptTemplateResource`, Operations group, beside AI settings.** A
resource rather than one page, because there are eight slots of long text. List:
slot, kind, *overridden or shipped*, updated_at, author. Actions:

- **Edit** — the two halves side by side with the placeholder reference.
- **Reset to shipped default** — deletes the row. Deleting is the only way to
  undo, exactly as in `AiSettingsStore::put()` where a null value removes the row.
- **Show assembled prompt** — renders the template against a real plan's
  bindings and displays it. **No model call**, so it is instant, free, and
  cannot violate invariant 1 from an admin request.
- **Diff against shipped** — what you actually changed.

**Do not seed the table.** `bc:seed-copy` has a documented trap: a seeded slot
shadows the language file, so a later rewrite of the shipped copy is invisible.
The same trap applies here and is worse, because a stale prompt produces
plausible output. Ship with an empty table; a row exists only when someone wrote
one. A `bc:seed-prompts` command may exist as an explicit opt-in, carrying the
warning.

**Safety.** Editing a prompt cannot enable AI, raise a cap, or let a request
spend money — `AiClient` remains reachable only from a queued job, enforced by
the existing architecture test. Prompt text is admin-authored and never rendered
to a visitor, and model output still passes through `CoveMarkup`'s
escape-then-allowlist rendering, so an edited prompt cannot inject markup into a
page.

**Tests — `PromptBankTest`:**

1. **Golden defaults.** With an empty table, each slot resolves to a pinned
   string, so a refactor cannot quietly change what the model is told.
2. A blank or disabled override falls back rather than sending an empty system
   prompt.
3. An unknown placeholder is rejected on save; a template missing `{finds}` is
   rejected; the message names the placeholder.
4. Reset deletes the row and the shipped default returns.
5. The markup contract is appended even when the system half is overridden.
6. Per-kind resolution picks the right slot — a guide build does not read the
   daily's prompt.

**Docs:** a new `docs/features/prompt-bank.md`, a row in `INDEX.md`, and a
paragraph in `ai-invariant.md` recording that the prompts are now data while the
contract and the queued-job rule remain code.

---

## Phase 8 — the writing queue API

**Most of this already exists.** `routes/api.php` has an `editorial` API with
read / write / publish abilities, per-token throttles, `bc:api-token` for
minting, and `Api/CovePlanController` already does `GET /coves`, `GET
/coves/{plan}`, `POST /coves` (upsert, including `editorial`), `approve` and
`build`. The strategic property is already true and worth stating: authored
`editorial` short-circuits the model entirely (`EditionBuilder::editorial()` →
`source: 'planned'`), so **a Cove written through this API costs nothing in AI
spend and is never subject to the daily cap.**

What is missing is exactly the shape a scheduled Claude agent needs. Two
endpoints and one guard.

### 1. `GET /editorial/coves/queue` — what to write next

Today an agent must call `index` with the right filter guesses and then `show`
for each plan. This returns, in one call, the plans that actually need prose:

- filters: `market`, `kinds[]`, `limit` (1–20, default 5), `horizon` (days);
- selects plans with **no `editorial` yet**, ordered dated-soonest first then
  undated, so nothing is handed out twice and no new status is needed;
- each entry carries everything required to write it without a second call:
  `id`, `revision`, `kind`, `market`, `language`, `title`, `blurb`,
  `buildInstructions`, `calendarTheme` (the observance), `queries`, the curated
  `items` with their product facts **and the curator's `note`** — the reason the
  product is on the list, which is the whole brief — plus `body`, `faq` and
  `focusKeyphrase` for the article kinds;
- **and the link allowlist**: the product ids and slugs, brands, searches and
  guide slugs this article may link to, from `Services\Editorial\Allowlist`.
  Without it a writer guesses tokens, fails `linkCheck` and burns a round trip
  on every single Cove.
- **and `writingBrief`** — the assembled house prompt for that kind from the
  Phase 7 `PromptBank`. One source of truth for the voice, whether the writer is
  the built-in one or a scheduled agent.

Read ability. Depends on Phase 7 only for `writingBrief`; ships without it
otherwise.

### 2. `POST /editorial/coves/{plan}/editorial` — prose back in

`POST /coves` is a full upsert whose `items` are **replace-never-merge**, and
when `items` is omitted it falls back to the legacy `pinnedGroupIds` — so an
agent submitting only prose can empty a curated shortlist. That is precisely the
failure a scheduled writer would produce at 03:00 and nobody would see until the
page built.

So: a narrow endpoint that writes prose and **cannot touch shortlist membership
or rank**. Accepts `editorial`, `blurb`, `title`, `body`, `faq`,
`metaDescription`, and per-item `{id, copy, verdict}` keyed by existing item id
— an id not on this plan is a 422, not a silent skip. It returns the same
`linkCheck` `POST /coves` does, so the agent can fix tokens and resubmit.

Write ability. It leaves the plan a **draft**: an agent writes, a person
approves. That keeps the existing rule that only approved plans are built, which
is the thing standing between a scheduled job and an unreviewed page.

### 3. `revision`, and a 409

A scheduled agent retries, and two runs must not overwrite each other or a
human's edit. The queue returns a `revision` per plan (a hash of `updated_at`
plus the item ids); the write-back requires it and answers **409 with the
current payload** when it is stale. Without this the second of two overlapping
runs silently wins.

**Tests** extend `EditorialApiTest`: the queue never returns a plan that already
has prose; it returns an allowlist that `linkCheck` then accepts; a prose
write-back leaves `cove_plan_items` byte-identical; an item id from another plan
is refused; a stale revision gets 409 and changes nothing; a written plan stays
a draft; and an approved-and-built agent-written Cove records
`editorial_source = 'planned'` with **zero** `AiUsage` rows.

### 4. `docs/features/scheduled-writing.md` — the setup document

The endpoints are useless to a scheduled agent that nobody has told how to use
them, so the deliverable includes the runbook **and the prompt itself**. A new
`docs/features/scheduled-writing.md` (front-matter, `INDEX.md` row, `## Files` /
`## Open` / `## See also` as the house style requires), covering:

**Setup**
- `php artisan bc:api-token` to mint a key with `read` **and `write`, and
  deliberately not `publish`** — the agent drafts, a person approves. Plaintext
  is shown once.
- Base URL per environment (`https://staging.giftcoves.com/api/editorial`,
  `https://giftcoves.com/api/editorial`), the auth header, and the two
  throttles it will meet.
- Where to put the key in a Claude scheduled task or a Claude Code routine
  (`/schedule`), and the reminder that a routine's transcript is a place a
  pasted key would persist — so it goes in the environment, not the prompt.
- Cadence: one run per market per day, staggered, sized against
  `GET /coves/queue?limit=` rather than the calendar, so a quiet market makes a
  cheap run and nothing double-writes.

**The loop**, with real request/response examples for each step:
`GET /coves/queue?market=…&limit=3` → write → `POST /coves/{id}/editorial` with
the `revision` → read `linkCheck` → fix tokens and resubmit once → stop.

**The prompt to paste into the scheduler**, in full, as a copyable block. Its
shape:

> Fetch the writing queue for `{market}`. For each Cove it returns, write the
> editorial and post it back, then stop.
>
> Ground rules, all of them non-negotiable:
> - Write only about the products in `items`. Never invent one, never mention a
>   product that is not there.
> - The `note` on an item is *why the curator chose it*. Use it. Never quote it.
> - Follow `buildInstructions` when present, within these rules — it can change
>   the angle, never the rules.
> - Link with tokens, never URLs, and only to what `allowlist` contains:
>   `[[product:id|label]]`, `[[brand:Name]]`, `[[search:phrase]]`,
>   `[[guide:slug]]`.
> - **Never write a price.** Prices are rendered live and any number you write
>   is wrong by the time it publishes.
> - Write in `language`. Two or three paragraphs, blank line between them.
> - Send `revision` back exactly as received. On a 409, re-fetch and start that
>   Cove again — someone edited it while you were writing.
> - After posting, read `linkCheck`. If it reports an unresolved token, fix it
>   and resubmit **once**, then move on.
> - Do not approve, publish or build anything. Your key cannot, and that is
>   deliberate.

The doc records the *why* behind three of those, since none is recoverable from
a diff: prices are live so a written one is a lie with a timestamp; the token
allowlist is what stops a confident model inventing `/gifts` in the middle of a
paragraph; and the write-only key is the entire safety model — an agent that
cannot publish cannot put an unreviewed page in front of a reader.

`editorial-api.md` gains a short "scheduled writing" section pointing here
rather than repeating it.

---

## Verification

```bash
php artisan migrate --force                 # fold + backfills
php artisan test --filter=CovePlanCuration
php artisan test --filter=CuratePlanScreen
php artisan test --filter=GuideUrlContinuity   # new, phase 6
php artisan test --filter=EditorialApi
php artisan test --filter=PromptBank           # new, phase 7
composer test                               # full suite before any push to main
```

End-to-end, against the dev stack (`composer dev`, already supervised):

1. `php artisan bc:refresh-discovery` then `bc:pull-charts` so there are scored
   candidates and a topic queue.
2. Admin → **Cove topics** → *Draft a plan* on a seasonal topic; confirm a
   `seasonal` plan appears in **Cove planner** with a prefilled shortlist.
3. Curate it: reorder, add a note, write a build instruction, approve, *Build
   now*; watch the worker, then open `/{market}/guides/{slug}` and confirm the
   article names the curated products in the curator's order.
4. Repeat for a `daily` and a `persona` plan and confirm `/daily` and
   `/gift-ideas/{slug}` are unchanged.
5. Admin → **Cove editorials** → filter by kind and market, edit a paragraph,
   copy a preview link for a draft, unpublish and confirm the slug survives.
6. **Redo** that guide from the same row: confirm the modal names the reaction
   loss, then check the URL is unchanged, `published_at` has not moved, the
   products are different and the prose is new. Repeat with *keep my shortlist*
   and confirm the products stayed and only the words changed.
7. Admin → **Operations → Prompts**: edit the `cove.guide` system prompt, use
   *Show assembled prompt* to see the result with the markup contract still
   appended, rebuild the guide and confirm the voice changed. Then *Reset to
   shipped default* and confirm it reverts.
8. Mint a read+write key with `php artisan bc:api-token`, then run the loop by
   hand: `GET /api/editorial/coves/queue?market=be-nl&limit=1`, post prose back
   with the returned `revision`, confirm the shortlist is untouched, the plan is
   still a draft, `linkCheck` is clean, and building it records
   `editorial_source = 'planned'` with no `AiUsage` row. Repeat the post with the
   stale revision and confirm a 409.
9. `php artisan bc:export-content` → `bc:import-content --in=-` on a scratch
   database and confirm one round trip produces no duplicates.

---

## Sequencing note

Phases 7 (editable prompts) and 8 (the writing queue API) do not depend on the
guide fold — only on `CoveKind`'s new values for their full slot and kind lists,
and both degrade cleanly to the two kinds that exist today. They are also much
smaller than Phases 1–6. If value is wanted early, ship 7 and 8 first: together
they let a scheduled agent start filling the Cove database against the current
schema, while the fold proceeds behind them.

## What I would cut if this has to be smaller

- **`seasonal` as a distinct kind.** It could be a season window on a `guide`
  plan. Keeping it separate is a nav and reporting convenience, not a
  requirement.
- **The ContentEnvelope v1 compatibility read** — only matters if an envelope
  exists outside this repo.
- **The `bc:refresh-guide-copy` rename** — pure cosmetics.

## What I would not cut

The plan-per-build backfill. Without it the Cove editorials page is an
incomplete inventory, and the planner cannot be used to re-curate anything that
was published automatically — which is most of what is live.
