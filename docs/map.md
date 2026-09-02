# Repository map

**Read this before grepping.** It exists because the transcripts show the same twelve entry points
being rediscovered session after session — `ls docs/features/` in 36 separate sessions, `ls tests/`
in 38, `grep -n "public function"` in 34, and [routes/web.php](../routes/web.php) (817 lines) opened
cold in 31. None of that found anything that wasn't already knowable. This file is the answer to
"where does this change go", so the first tool call of a session can be the edit.

It deliberately records **structure**, not behaviour. Behaviour belongs in
[features/INDEX.md](features/INDEX.md), one `.md` per feature, and that index is the second thing to
read once you know which feature you are in.

---

## Where a change goes

| If the change is about… | Start here |
|---|---|
| a URL, a new page, a redirect | [routes/web.php](../routes/web.php) — one `Route::prefix('{market}')` group holds nearly everything |
| visible English/Dutch/French/Spanish text | `lang/{en,nl,fr,es}/site.php` — **all four**, always |
| what a page renders | `resources/js/Pages/<Name>.tsx`, named after the controller |
| chrome shared by every page | [resources/js/Layouts/SiteLayout.tsx](../resources/js/Layouts/SiteLayout.tsx) |
| props every page receives | [app/Http/Middleware/HandleInertiaRequests.php](../app/Http/Middleware/HandleInertiaRequests.php) |
| a business rule | `app/Services/<Area>/` — never a controller, never a job |
| a knob, cap, weight or threshold | [config/giftcoves.php](../config/giftcoves.php) (1,074 lines) |
| the admin panel | `app/Filament/Resources/<Thing>/` or `app/Filament/Pages/<Thing>.php` |
| a market | [app/Enums/Market.php](../app/Enums/Market.php) — the single source of truth |
| a merchant or feed source | [app/Enums/Source.php](../app/Enums/Source.php) + `app/Services/Connectors/<Vendor>/` |
| the schema | `database/migrations/` — forward-only, expand/contract |
| the editorial API Claude-on-the-web calls | [routes/api.php](../routes/api.php) + `app/Http/Controllers/Api/` |

## The request path

```
/{market}/...  →  SetMarket (resolves App\Enums\Market from the prefix)
               →  HandleInertiaRequests (shares market, auth, nav, translations)
               →  App\Http\Controllers\<X>Controller
               →  App\Services\<Area>\<Thing>   ← the decisions live here
               →  Inertia::render('<Page>')     → resources/js/Pages/<Page>.tsx
```

Unprefixed by design, because they are about the *visitor* rather than the catalogue: `/`
(302 to a market, never 301), `/market` (switcher POST — the only writer of the `bc_market` cookie),
`/consent`, `/health`, `/robots.txt`, `/sitemap*.xml`, `/auth/google/callback`,
`/webhooks/ebay/account-deletion`.

Middleware worth knowing by name: `SetMarket`, `HandleInertiaRequests`, `RedirectLegacyHost`
(canonical host), `TrackAnonymousIdentity`, `EnsureUserIsAdmin`, `AuthenticateApiToken` +
`RequireApiAbility`.

## The route surface

Grouped by what a visitor is doing, not by file order:

- **Find** — `/search`, `/search-help`, `/scan`, `/scan/{barcode}`, `/brands`, `/brand/{slug}`,
  `/shops`, `/shops/{slug}`, `/p/{group}/{slug?}`, `/go/{offer}` (every outbound link),
  `/track/click`
- **Discover** — `/daily`, `/daily/{date}`, `/discover/{mode?}`, `/discover-cove`, `/surprise`,
  `/coves`, `/guides`, `/guides/{slug}`, `/gift-ideas`, `/gift-cove`, `/ask`
- **Organize** — `/lists`, `/lists/{list}`, `/list-options`, `/saved-items`, `/l/{token}` (shared
  list: claim, pledge, vote, suggest), `/for/{token}`, `/q/{token}` (quiz), `/santa/**`
- **Account** — `/login`, `/auth/magic/{token}`, `/auth/google`, `/logout`, `/notifications`,
  `/alerts`
- **Machine** — `/og/**.png`, `/health`, sitemaps, `/api/editorial/**`

## Services, one line each

| Directory | What it decides |
|---|---|
| `Ai/` | the only place AI is called — `AiClient`, `PromptBank`, `AiUnavailable` |
| `Alerts/` | when a price/restock alert is allowed to fire |
| `Auth/` | merging an anonymous identity into a signed-in one |
| `Catalogue/` | brand stats, excerpts, product descriptions, Awin feed discovery |
| `Charts/` | bestseller charts — the demand signal |
| `Community/` | screening user-written posts and answers |
| `Connectors/` | one subdirectory per vendor; `Offer` is the shared shape |
| `Content/` | shipped editorial (advice coves), guide folding |
| `Cove/` | the daily edition: themes, observances, digests, plan slugs |
| `Curation/` | the human pass over a drafted plan |
| `Discover/` | discovery modes — `ModeEngine`, `Ranker`, `ModeProfile` |
| `Discovery/` | catalogue-level signals: trends, serendipity, freshness |
| `Editorial/` | the API's view of products; link checking; allowlist |
| `Gift/` | giftability, suggestions, Secret Santa draw, quizzes, taste briefs |
| `Guides/` | topic mining and planning |
| `Identity/` | GTIN parsing and `identity_key` resolution — see invariant 2 |
| `Ingestion/` | offer upsert and grouping — the write path for feeds |
| `Ops/` | deploy trigger, market supply |
| `Pages/` | editable page templates and copy blocks |
| `Search/` | `SearchService` (668 lines), `SearchQuery`, Amazon links, brand attribution |
| `Seo/` | meta, OG images, structured data, alternates, legacy redirects |
| `Settings/` | admin-editable settings backed by the database |
| `Social/` | the follow graph |
| `Wishlist/` | saving, claim visibility (`ClaimView` — see invariant 4), invitations |

## Copy and translation

`lang/{en,nl,fr,es}/site.php` — one flat PHP array each, 1,400–1,650 lines. English is the longest
because it is written first.

**A key added to one file must be added to all four.** `tests/Feature/LocalisationTest.php` is the
gate, and it is the test to run after any copy change. The React side reads them through
[resources/js/useTranslations.ts](../resources/js/useTranslations.ts).

Editable-in-admin copy is a different system: `app/Services/Pages/` plus the `PageBlock` /
`PageBlockVariant` models, documented in [features/page-templates.md](features/page-templates.md).

## Admin

Filament 5 at `/admin`, gated on `users.is_admin`.

- **Resources** (CRUD over a model): AiUsage, ApiTokens, CommunityPosts, CoveEditorials, CovePlans,
  Feedback, Feeds, GuideTopics, IngestionJobs, Merchants, ModeProfiles, Products, PromptTemplates
- **Pages** (custom): AiSettings, DiscoverAwinFeeds, EditPageTemplate, MarketSupply, MarketTrends,
  Migration

Styling gotcha, and it looks exactly like a page nobody styled: Filament's prebuilt stylesheet ships
**no** Tailwind utilities. `resources/css/filament/admin/theme.css` supplies them, scanned from
`app/Filament` and `resources/views/filament`. Full reasoning in the Conventions section of
[.claude/CLAUDE.md](../.claude/CLAUDE.md).

## Tests

120 files in `tests/Feature/`, named after the feature rather than the class — `SearchTest`,
`BrandPageTest`, `LocalisationTest`, `AdminPanelTest`, `SaveToListTest`, `MarketSupplyTest`. So the
filter you want is usually the feature's name, guessed correctly on the first try:

```bash
php artisan test --filter=BrandPageTest           # Bash tool
php artisan test --% --filter=BrandPageTest       # PowerShell tool needs --%
```

Reach for the narrowest filter that covers the edit, and say which one ran. The full suite is for one
moment only — before a push to `main`. `tests/TestCase.php` holds the shared setup; `tests/Unit/`
holds the pure ones, including `ConfigContractTest`, which fails the build when a config key cannot
reach a container.

## Files big enough to read in slices

| File | Lines | Read it for |
|---|---|---|
| [lang/en/site.php](../lang/en/site.php) | 1,649 | every visible string |
| [config/giftcoves.php](../config/giftcoves.php) | 1,074 | caps, weights, feature keys, market config |
| [routes/web.php](../routes/web.php) | 817 | the whole URL surface, heavily commented |
| [app/Services/Search/SearchService.php](../app/Services/Search/SearchService.php) | 668 | ranking, trigram fallback, market filtering |
| [resources/js/Layouts/SiteLayout.tsx](../resources/js/Layouts/SiteLayout.tsx) | 658 | nav, footer, mobile menu |

## Related documents

- [.claude/CLAUDE.md](../.claude/CLAUDE.md) — invariants, conventions, shell facts. Loaded every session.
- [features/INDEX.md](features/INDEX.md) — 65 features, one `.md` each, with the *why*.
- [deployment.md](deployment.md) — two apps, one branch, the production trigger.
- [local-dev.md](local-dev.md) — the supervised dev stack, Herd, Smart App Control.
- [testing.md](testing.md) — why 8 processes locally and 4 in CI.
- [TODO.md](TODO.md) — merged but not yet proven.
