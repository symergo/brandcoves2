# Feature index

One `.md` per feature. Record *why* a non-obvious decision was made — the reasoning is the part that
cannot be recovered from a diff.

| Feature | Area | Status |
|---|---|---|
| [market-routing.md](market-routing.md) | Core | Active |
| [localisation.md](localisation.md) | Core / Frontend | Active |
| [ingestion.md](ingestion.md) | Catalogue | Active |
| [product-identity.md](product-identity.md) | Catalogue | Active |
| [search.md](search.md) | Search | Partial — schema + indexes only |
| [barcode-scanner.md](barcode-scanner.md) | Search / Mobile | Planned — late Phase 2 / early Phase 3 |
| [gift-whisperer.md](gift-whisperer.md) | Gifting | Planned |
| [daily-picks.md](daily-picks.md) | Discovery | Planned |
| [buying-guides.md](buying-guides.md) | Content | Planned |
| [wishlists.md](wishlists.md) | Wishlist | Planned |
| [ai-invariant.md](ai-invariant.md) | Core | Active |

## Known gap to close before Phase 6

**Pages are currently client-rendered.** Inertia is running without SSR, so the
HTML shell carries only the JSON payload and React renders in the browser. That
is fine for the wizard and the wishlist tray, but buying guides, per-pick pages
and product pages are the entire SEO growth model and must not depend on the
crawler executing JavaScript.

Two options, to be decided in Phase 2: enable Inertia SSR
(`@inertiajs/react/server` + a `php artisan inertia:start-ssr` container), or
render the SEO-critical routes as Blade and keep Inertia/React for the
interactive surfaces only. The second is closer to what CLAUDE.md already
describes ("Blade for the document shell only" was too narrow a reading).

## Build phases

| # | Phase | Status |
|---|---|---|
| 0 | Foundation: schema, market routing, admin shell, deploy pipeline | ✅ Done |
| 0.5 | Staging deployed and verified at `staging.brandcoves.com` | ✅ Done 2026-08-07 |
| 1 | Ingestion & catalogue — Awin feeds, bol live, grouping, price history | Next |
| 2 | Search & offer comparison | |
| 3 | Accounts & wishlists | |
| 4 | Gift Whisperer | |
| 5 | Daily Picks | |
| 6 | Buying guides | |
| 7 | Admin, SEO, cutover from v1 | |
| 8 | Deferred: Amazon, catalogue breadth, embeddings | |
