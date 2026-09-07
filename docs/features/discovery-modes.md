---
name: Discovery modes
area: Core / Discovery
status: Removed 2026-09-07
date_added: 2026-08-08
---

# Discovery modes (removed)

The dial page at `/discover/{mode}`: one ranking pipeline reconfigured by a mode profile, with
nine retrievers (popular, fresh, value, curated, keyword, outlier, slots, spectrum, two-tower)
blended by a scorer, a continuous control from "I know what I want" to "surprise me", a
"not for me" reaction that tuned the weights, and an admin resource for the per-mode weights.

Removed on 2026-09-07 at the owner's request. What the 2026-09-06 review found about it is the
background: it was indexable and in the sitemap per mode, but reachable from nothing on the
site; it re-ranked on arrival with the values the server had just used; its "not for me"
dropped a card with no recovery; and the line under the dial printed the scoring weights to
visitors. A door was added the day before, and the decision the day after was that the surface
did not earn its place beside the Daily Cove, Surprise, the guides and the personas, which
answer the same "show me something" with editorial rather than a slider.

## What went

- `App\Http\Controllers\DiscoverController`, the three routes, the sitemap's per-mode listing,
  the `discover` editorial link target, the nav entry and `Pages/Discover.tsx`.
- `App\Services\Discover\*` — engine, registry, ranker, profile, request, result, candidate and
  the retrievers — and their bindings in `AppServiceProvider`.
- `App\Models\DiscoveryReaction` and `ModeProfileRecord`, the `ModeProfiles` admin resource, and
  the two tables, dropped by `2026_09_07_000100_drop_the_discovery_modes`.
- The `discover.*` copy group (45 keys) in four languages, and three test files.

## What stayed

`App\Services\Discovery\*` is a different namespace — catalogue-level signals (trends,
serendipity, freshness, catalogue age) that the Surprise page, the Daily Cove builder and the
charts use. `CatalogueAge` lost its one mode-specific test and kept the rest. `/discover-cove`,
the hub page, is unrelated and stays.
