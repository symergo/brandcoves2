# Feature index

One `.md` per feature. Record *why* a non-obvious decision was made — the reasoning is the part that
cannot be recovered from a diff.

| Feature | Area | Status |
|---|---|---|
| [market-routing.md](market-routing.md) | Core | Active |
| [localisation.md](localisation.md) | Core / Frontend | Active |
| [navigation.md](navigation.md) | Core / Frontend | Active |
| [homepage.md](homepage.md) | Core / Frontend | Active |
| [ingestion.md](ingestion.md) | Catalogue | Active |
| [popularity-charts.md](popularity-charts.md) | Catalogue / Discovery | Active — bol; Amazon on the same seam |
| [product-identity.md](product-identity.md) | Catalogue | Active |
| [search.md](search.md) | Search | Active |
| [seo.md](seo.md) | SEO / Frontend | Active |
| [brand-mark.md](brand-mark.md) | Brand / Frontend | Active |
| [social-cards.md](social-cards.md) | SEO / Brand | Active |
| [brand-pages.md](brand-pages.md) | SEO / Discovery | Active |
| [barcode-scanner.md](barcode-scanner.md) | Search / Mobile | Active |
| [amazon-link-paste.md](amazon-link-paste.md) | Search | Active — ASIN redirect waits on the connector |
| [gift-whisperer.md](gift-whisperer.md) | Gifting | Active |
| [gifting-lenses.md](gifting-lenses.md) | Gifting / Core | Active |
| [secret-santa.md](secret-santa.md) | Gifting / Social | Active |
| [list-quiz.md](list-quiz.md) | Gifting / Growth | Active |
| [sharing.md](sharing.md) | Gifting / Growth | Active |
| [serendipity.md](serendipity.md) | Discovery | Active |
| [discovery-modes.md](discovery-modes.md) | Core / Discovery | Phase 2 active — 7 of 9 modes |
| [daily-cove.md](daily-cove.md) | Discovery / Content | Active |
| [cove-subscriptions.md](cove-subscriptions.md) | Discovery / Email | Active |
| [editorial-api.md](editorial-api.md) | Content / Operations | Active |
| [content-promotion.md](content-promotion.md) | Content / Operations | Active |
| [config-contract.md](config-contract.md) | Core / Operations | Active |
| [wishlists.md](wishlists.md) | Wishlist / Alerts | Active |
| [ai-invariant.md](ai-invariant.md) | Core | Active |
| [legal-pages.md](legal-pages.md) | Compliance / Content | Active — fr/es untranslated |
| [cutover.md](cutover.md) | Operations | ✅ Done 2026-08-10 |
| [rebrand.md](rebrand.md) | Core / Operations | Code done — Coolify and third-party accounts outstanding |

## Closed in Phase 2

The client-rendering gap flagged after Phase 0 is resolved: Inertia SSR now runs
as its own Node container, so crawlers receive fully rendered HTML. See
[seo.md](seo.md).

## Build phases

| # | Phase | Status |
|---|---|---|
| 0 | Foundation: schema, market routing, admin shell, deploy pipeline | ✅ Done |
| 0.5 | Staging deployed and verified at `staging.giftcoves.com` | ✅ Done 2026-08-07 |
| 1 | Ingestion & catalogue — Awin feeds, bol live, grouping, price history | ✅ Done |
| 2 | Search & offer comparison | ✅ Done |
| 3 | Accounts & wishlists, sharing, claiming, alerts, inbox | ✅ Done |
| 4 | Gift Whisperer + Serendipity Engine | ✅ Done |
| 5 | The Daily Cove — Daily Picks and buying guides merged into one daily edition | Next |
| 6 | *(folded into Phase 5)* | |
| 7 | Admin, SEO, cutover from v1 | ✅ Done — cutover executed 2026-08-10 |
| 8 | Deferred: Amazon, catalogue breadth, embeddings | |
| 9 | Gifting lenses: recipient linking, Secret Santa, co-givers, the quiz, occasion reminders | ✅ Done |
