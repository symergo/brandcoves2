# Repository map

**Read this before grepping.** It exists because the transcripts show the same twelve entry points
being rediscovered session after session — `ls docs/features/` in 36 separate sessions, `ls tests/`
in 38, `grep -n "public function"` in 34, and [routes/web.php](../routes/web.php) (then 817 lines) opened
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
| a knob, cap, weight or threshold | [config/giftcoves.php](../config/giftcoves.php) (1,713 lines) |
| the admin panel | `app/Filament/Resources/<Thing>/` or `app/Filament/Pages/<Thing>.php` |
| a market | [app/Enums/Market.php](../app/Enums/Market.php) — the single source of truth |
| a merchant or feed source | [app/Enums/Source.php](../app/Enums/Source.php) + `app/Services/Connectors/<Vendor>/` |
| the schema | `database/migrations/` — forward-only, expand/contract |
| the editorial API Claude-on-the-web calls | [routes/api.php](../routes/api.php) + `app/Http/Controllers/Api/` |
| the browser extension that imports a bol or Amazon page | [extension/](../extension/) + `app/Services/Ingestion/{Bol,Amazon}PageImport.php` — see [features/page-import.md](features/page-import.md) |

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
- **Find a gift** — `/gift` (one flow: who, then questions, This or that or a type; see
  features/find-a-gift.md), `/gift/taste` (This or that), `/gift/card/{token}` (a gift profile
  card), `/t/{token}` (This or that together)
- **Discover** — `/daily`, `/daily/{date}`, `/discover-cove`, `/surprise`,
  `/coves`, `/coves/community`, `/coves/community/{slug}` (lists people published),
  `/guides`, `/guides/{slug}`, `/gift-ideas`, `/gift-ideas/for/{recipient}/{interest?}`
  (gift landing pages), `/gift-cove`, `/ask`
- **Organize** — `/lists`, `/lists/{list}`, `/list-options`, `/saved-items`, `/l/{token}` (shared
  list: claim, pledge, vote, suggest), `/for/{token}`, `/q/{token}` (quiz), `/santa/**`,
  `/people` (My people: saved people and friends on one list; `/friends` redirects here),
  `/people/{recipient}` (a saved person's gift history and next step)
- **Account** — `/login`, `/auth/magic/{token}`, `/auth/google`, `/logout`, `/notifications`,
  `/alerts`, `/reminders/stop/{user}` (signed, from every reminder email),
  `/invites/not-wanted/{inviter}/{hash}` (signed, "this is spam" from every invitation email),
  `/invites/accept/{token}` (the invitation's button: signs a new invitee straight in)
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
| `Cove/` | the daily edition: themes, observances, digests, plan slugs, seasonal series, the editorial year; personas drafted from search demand (`PersonaDemandPlanner`, features/persona-demand.md) |
| `Curation/` | the human pass over a drafted plan |
| `Discovery/` | catalogue-level signals: trends, serendipity, freshness |
| `Editorial/` | the API's view of products; link checking; allowlist |
| `Gift/` | giftability, suggestions, Secret Santa draw, quizzes, taste briefs; gift history (`GiftHistory`), the next step after a past gift (`NextSteps`, scored by `NextStepScorer`) and the ideas in a reminder (`ReminderIdeas`); see features/gift-history.md; gift searches counted as readings (`GiftSearchDemand`), a persona's budget tabs (`PersonaBudgets`), what gets used up or done (`HasEverything`) |
| `Guides/` | topic mining and planning |
| `Ideas/` | offline items people typed by hand, folded (`IdeaKey`), counted nightly from five people (`OfflineIdeaCounter`) and, once a person approved them, matched to a brief (`OfflineIdeaPicker`); see features/offline-ideas.md |
| `Identity/` | GTIN parsing and `identity_key` resolution — see invariant 2; merges, splits and the match rules (`GroupMerger`, `GroupSplitter`, `MatchFinder`, `ModelNumber`) |
| `Ingestion/` | offer upsert and grouping — the write path for feeds |
| `Ops/` | config report, market supply |
| `Pages/` | editable page templates and copy blocks |
| `Search/` | `SearchService`, `SearchQuery`, the gift-intent reading of the search box (`GiftIntentParser`), Amazon links, brand attribution; the Coves a term matches (`CoveMatches`) and what people keep for it (`SearchSignals`), features/search.md |
| `Seo/` | meta, OG images, structured data, alternates, legacy redirects |
| `Settings/` | admin-editable settings backed by the database |
| `Social/` | friends (`Friends`, `FriendInvites`, `ShareReferral`), the invitation email and its limits and spam link (`InviteMailer`), sharing a list with a named friend (`ListSharer`), My people (`MyPeople`: saved people and friends on one list); `FollowGraph` is built and unused |
| `Wishlist/` | saving (`ItemSaver`), making a list in one step (`ListMaker`, `DefaultTitle`), claim visibility (`ClaimView` — see invariant 4), the group-gift board |
| `PageReading/` | a pasted link, read in a queued job: known sources first (`LinkRouter`), then Iframely or the page itself through `SafeFetch` (private addresses refused); see features/pasted-links.md |
| `Images/` | a picture copied to our own storage and re-encoded (`ImageStore`) |
| `Notifications/` | the inbox rows list activity writes (`ListActivity`) |
| `Mail/` | admin-editable email templates (`MailTemplates`) |
| `Shops/` | the shop directory |

## Copy and translation

`lang/{en,nl,fr,es}/site.php` — one PHP array each, 2,300–2,600 lines (2026-09-26). English is the
longest because it is written first and carries the comments.

**A key added to one file must be added to all four.** `tests/Feature/LocalisationTest.php` is the
gate, and it is the test to run after any copy change. The React side reads them through
[resources/js/useTranslations.ts](../resources/js/useTranslations.ts).

Editable-in-admin copy is a different system: `app/Services/Pages/` plus the `PageBlock` /
`PageBlockVariant` models, documented in [features/page-templates.md](features/page-templates.md).

## Admin

Filament 5 at `/admin`, gated on `users.is_admin`.

- **Resources** (CRUD over a model): AiUsage, ApiTokens, CommunityCoves (published lists, hide or
  show), CommunityPosts, CoveEditorials, CovePlans,
  Feedback, Feeds, GuideTopics, IngestionJobs, Merchants, ModeProfiles, ProductGroups (Catalogue >
  Products: merge and split), Products (the offers), PromptTemplates, Users (Operations > Accounts:
  find a person, grant or remove panel access, delete an account)
- **Pages** (custom): AffiliateSettings, AiSettings, Automation, CoveCalendar, DiscoverAwinFeeds,
  EditPageTemplate, EmailTemplates, MarketSupply, MarketTrends, MatchReview (the queue of products
  that may be one), Migration, OfflineIdeaReview (hand-typed ideas waiting for a person),
  ReminderSettings

Styling gotcha, and it looks exactly like a page nobody styled: Filament's prebuilt stylesheet ships
**no** Tailwind utilities. `resources/css/filament/admin/theme.css` supplies them, scanned from
`app/Filament` and `resources/views/filament`. Full reasoning in the Conventions section of
[.claude/CLAUDE.md](../.claude/CLAUDE.md).

## Tests

196 files in `tests/Feature/` (2026-09-26), named after the feature rather than the class — `SearchTest`,
`BrandPageTest`, `LocalisationTest`, `AdminPanelTest`, `SaveToListTest`, `MarketSupplyTest`. So the
filter you want is usually the feature's name, guessed correctly on the first try:

```bash
php artisan test --filter=BrandPageTest           # Bash tool
php artisan test --% --filter=BrandPageTest       # PowerShell tool needs --%
```

Reach for the narrowest filter that covers the edit, and say which one ran. The full suite runs in CI
on every push; run it locally only when asked, or when a change touches migrations or shared services
(see [testing.md](testing.md)). `tests/TestCase.php` holds the shared setup; `tests/Unit/`
holds the pure ones, including `ConfigContractTest`, which fails the build when a config key cannot
reach a container.

## Files big enough to read in slices

| File | Lines | Read it for |
|---|---|---|
| [lang/en/site.php](../lang/en/site.php) | 2,596 | every visible string |
| [config/giftcoves.php](../config/giftcoves.php) | 1,713 | caps, weights, feature keys, market config |
| [routes/web.php](../routes/web.php) | 1,311 | the whole URL surface, heavily commented |
| [app/Services/Search/SearchService.php](../app/Services/Search/SearchService.php) | 668 | ranking, trigram fallback, market filtering |
| [resources/js/Layouts/SiteLayout.tsx](../resources/js/Layouts/SiteLayout.tsx) | 658 | nav, footer, mobile menu |

## Related documents

- [.claude/CLAUDE.md](../.claude/CLAUDE.md) — invariants, conventions, shell facts. Loaded every session.
- [features/INDEX.md](features/INDEX.md) — 65 features, one `.md` each, with the *why*.
- [deployment.md](deployment.md) — two apps, one branch, the production trigger.
- [local-dev.md](local-dev.md) — the supervised dev stack, Herd, Smart App Control.
- [testing.md](testing.md) — why 8 processes locally and 4 in CI.
- [TODO.md](TODO.md) — merged but not yet proven.
