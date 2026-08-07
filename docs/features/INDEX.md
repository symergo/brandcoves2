# Feature index

One `.md` per feature. Record *why* a non-obvious decision was made — the reasoning is the part that
cannot be recovered from a diff.

| Feature | Area | Status |
|---|---|---|
| [market-routing.md](market-routing.md) | Core | Active |
| [product-identity.md](product-identity.md) | Catalogue | Designed |
| [search.md](search.md) | Search | Partial — schema + indexes only |
| [gift-whisperer.md](gift-whisperer.md) | Gifting | Planned |
| [daily-picks.md](daily-picks.md) | Discovery | Planned |
| [buying-guides.md](buying-guides.md) | Content | Planned |
| [wishlists.md](wishlists.md) | Wishlist | Planned |
| [ai-invariant.md](ai-invariant.md) | Core | Active |

## Build phases

| # | Phase | Status |
|---|---|---|
| 0 | Foundation: schema, market routing, admin shell, deploy pipeline | ✅ Done locally; not yet deployed |
| 1 | Ingestion & catalogue — Awin feeds, bol live, grouping, price history | Next |
| 2 | Search & offer comparison | |
| 3 | Accounts & wishlists | |
| 4 | Gift Whisperer | |
| 5 | Daily Picks | |
| 6 | Buying guides | |
| 7 | Admin, SEO, cutover from v1 | |
| 8 | Deferred: Amazon, catalogue breadth, embeddings | |
