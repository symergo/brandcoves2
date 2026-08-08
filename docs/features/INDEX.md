# Feature index

One `.md` per feature. Record *why* a non-obvious decision was made — the reasoning is the part that
cannot be recovered from a diff.

| Feature | Area | Status |
|---|---|---|
| [market-routing.md](market-routing.md) | Core | Active |
| [localisation.md](localisation.md) | Core / Frontend | Active |
| [ingestion.md](ingestion.md) | Catalogue | Active |
| [product-identity.md](product-identity.md) | Catalogue | Active |
| [search.md](search.md) | Search | Active |
| [seo.md](seo.md) | SEO / Frontend | Active |
| [barcode-scanner.md](barcode-scanner.md) | Search / Mobile | Planned — late Phase 2 / early Phase 3 |
| [gift-whisperer.md](gift-whisperer.md) | Gifting | Active |
| [serendipity.md](serendipity.md) | Discovery | Active |
| [discovery-modes.md](discovery-modes.md) | Core / Discovery | Phase 2 active — 7 of 9 modes |
| [daily-cove.md](daily-cove.md) | Discovery / Content | In progress |
| [wishlists.md](wishlists.md) | Wishlist / Alerts | Active |
| [ai-invariant.md](ai-invariant.md) | Core | Active |

## Closed in Phase 2

The client-rendering gap flagged after Phase 0 is resolved: Inertia SSR now runs
as its own Node container, so crawlers receive fully rendered HTML. See
[seo.md](seo.md).

## Build phases

| # | Phase | Status |
|---|---|---|
| 0 | Foundation: schema, market routing, admin shell, deploy pipeline | ✅ Done |
| 0.5 | Staging deployed and verified at `staging.brandcoves.com` | ✅ Done 2026-08-07 |
| 1 | Ingestion & catalogue — Awin feeds, bol live, grouping, price history | ✅ Done |
| 2 | Search & offer comparison | ✅ Done |
| 3 | Accounts & wishlists, sharing, claiming, alerts, inbox | ✅ Done |
| 4 | Gift Whisperer + Serendipity Engine | ✅ Done |
| 5 | The Daily Cove — Daily Picks and buying guides merged into one daily edition | Next |
| 6 | *(folded into Phase 5)* | |
| 7 | Admin, SEO, cutover from v1 | |
| 8 | Deferred: Amazon, catalogue breadth, embeddings | |
