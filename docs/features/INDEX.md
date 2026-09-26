# Feature index

One `.md` per feature. Record *why* a non-obvious decision was made — the reasoning is the part that
cannot be recovered from a diff. Each doc's `date_added` says when it was new; this table says what
is true now.

| Feature | Area | Status |
|---|---|---|
| [market-routing.md](market-routing.md) | Core | Active |
| [not-found.md](not-found.md) | Core / Frontend | Active |
| [list-help.md](list-help.md) | Core / Frontend | Active |
| [auth.md](auth.md) | Core / Accounts | Active — Google needs credentials per environment |
| [user-admin.md](user-admin.md) | Core / Accounts / Admin | Active |
| [affiliate-settings.md](affiliate-settings.md) | Admin / Connectors | Active |
| [display-titles.md](display-titles.md) | Catalogue / Editorial | Active |
| [gift-tags.md](gift-tags.md) | Catalogue / Gifting / Editorial | Active |
| [localisation.md](localisation.md) | Core / Frontend | Active |
| [navigation.md](navigation.md) | Core / Frontend | Active |
| [homepage.md](homepage.md) | Core / Frontend | Active |
| [amazon-compliance.md](amazon-compliance.md) | Core / Compliance | Active — rules enforced in `Source`; read before touching Amazon data |
| [ingestion.md](ingestion.md) | Catalogue | Active |
| [page-import.md](page-import.md) | Ingestion / Editorial | Active — Chrome extension in `extension/` |
| [popularity-charts.md](popularity-charts.md) | Catalogue / Discovery | Active — bol; Amazon on the same seam |
| [ebay-account-deletion.md](ebay-account-deletion.md) | Catalogue / Compliance | Active — live since the 2026-08-31 build; registering in eBay's portal still outstanding, see [TODO](../TODO.md) |
| [ebay-connector.md](ebay-connector.md) | Catalogue | Merged, unverified — keyset rejected until the account-deletion endpoint is registered; see [TODO](../TODO.md) |
| [tradedoubler-connector.md](tradedoubler-connector.md) | Catalogue | Merged, unverified — supplied token is rejected, see [TODO](../TODO.md) |
| [market-supply.md](market-supply.md) | Catalogue / Operations | Active |
| [source-switch.md](source-switch.md) | Catalogue / Operations | Active |
| [product-identity.md](product-identity.md) | Catalogue | Active — merges and splits live in identity (aliases, overrides) since 2026-09-27 |
| [match-review.md](match-review.md) | Catalogue / Admin | Active — rules propose, a person decides every pair at /admin/match-review; no auto-merge yet |
| [product-signals.md](product-signals.md) | Catalogue / Discovery | Active — price range, saved by (from 5 people), found in Coves, related |
| [product-titles.md](product-titles.md) | Catalogue / SEO | Active |
| [search.md](search.md) | Search | Active |
| [list-signals.md](list-signals.md) | Gifting / Catalogue | Active — crowd tags and product links from lists, nightly; ideas in the same spirit on shared wish lists |
| [crowd-picks.md](crowd-picks.md) | Gifting | Active — "chosen by others for someone like them": products on 5+ people's lists for the same kind of person rank higher in the Gift Finder and This or that; nothing shows until then |
| [offline-ideas.md](offline-ideas.md) | Gifting / Wishlist / Admin | Active — hand-typed items five people wrote, approved by a person, shown under Gift Finder and This or that results |
| [intent-search.md](intent-search.md) | Search / Gifting | Active — the search box reads gift searches (who, interests, occasion, budget) |
| [gift-landing-pages.md](gift-landing-pages.md) | Gifting / SEO | Active — `/gift-ideas/for/{recipient}/{interest}`, recorded nightly when 8+ products fit; briefs stored and linkable; Cove plans can carry a brief |
| [search-urls.md](search-urls.md) | Search / SEO | Active — `/be-nl/zoek/term`, the market's word in the path |
| [seo.md](seo.md) | SEO / Frontend | Active |
| [page-titles.md](page-titles.md) | SEO / Frontend | Active |
| [analytics.md](analytics.md) | SEO / Compliance | Active — production only, behind a consent banner |
| [brand-mark.md](brand-mark.md) | Brand / Frontend | Active |
| [design-system.md](design-system.md) | Brand / Frontend | Active — tokens, Button, Badge, the navigation beam; most call sites not yet migrated |
| [social-cards.md](social-cards.md) | SEO / Brand | Active |
| [brand-pages.md](brand-pages.md) | SEO / Discovery | Active |
| [barcode-scanner.md](barcode-scanner.md) | Search / Mobile | Active |
| [popular-searches.md](popular-searches.md) | Search / SEO | Active |
| [crawlers-and-the-search-log.md](crawlers-and-the-search-log.md) | Search / SEO | Active |
| [search-help.md](search-help.md) | Search / Content | Active |
| [search-alerts.md](search-alerts.md) | Search / Alerts | Active — in-app only |
| [list-price-watch.md](list-price-watch.md) | Wishlist / Alerts | Active — one digest a morning |
| [feedback.md](feedback.md) | Core / Quality | Active |
| [product-description.md](product-description.md) | Catalogue / Frontend | Active |
| [amazon-link-paste.md](amazon-link-paste.md) | Search | Active — ASIN redirect works for ASINs imported with a barcode (page import) |
| [amazon-search-cta.md](amazon-search-cta.md) | Search / Affiliate | Active on search, brand and product; `nl-nl` + both `be-*`, no tag for `en`/`es` |
| [gift-whisperer.md](gift-whisperer.md) | Gifting | Active — board of eight; back in the header since 2026-09-14 |
| [taste-discovery.md](taste-discovery.md) | Gifting | Active — This or that: a dozen choices between products become a taste, a budget and ideas; can be kept on a person |
| [discover-cove.md](discover-cove.md) | Core / Discovery | Active, rebuilt 2026-09-26: today's Cove, This or that, then six of each |
| [giftability.md](giftability.md) | Gifting / Catalogue | Active |
| [gifting-lenses.md](gifting-lenses.md) | Gifting / Core | Active |
| [secret-santa.md](secret-santa.md) | Gifting / Social | Active — chain repair built; year-on-year reuse open |
| [list-quiz.md](list-quiz.md) | Gifting / Growth | Active |
| [sharing.md](sharing.md) | Gifting / Growth | Active |
| [list-board.md](list-board.md) | Gifting / Coordination | Active |
| [occasion-reminders.md](occasion-reminders.md) | Gifting / Notifications | Active — four dates; windows editable in admin (friend birthdays on fixed ones); ideas about two weeks out; a per-person stop link since 2026-09-28 |
| [gift-history.md](gift-history.md) | Gifting / Notifications | Active — `/people/{id}`: what you gave (noted, and your own claims only), never suggested again, the next step after it; three ideas in the reminder about two weeks out |
| [recipient-birthday.md](recipient-birthday.md) | Gifting / Notifications | Active — day and month, never a year |
| [copying-items.md](copying-items.md) | Wishlist / Gifting | Active — copy only, never move |
| [serendipity.md](serendipity.md) | Discovery | Active |
| [recently-viewed.md](recently-viewed.md) | Discovery / Frontend | Active |
| [ask-others.md](ask-others.md) | Discovery / Community | Active |
| [discovery-modes.md](discovery-modes.md) | Core / Discovery | Removed 2026-09-07 |
| [daily-cove.md](daily-cove.md) | Discovery / Content | Active |
| [all-coves.md](all-coves.md) | Discovery / Content | Active |
| [cove-rail.md](cove-rail.md) | Discovery / Content | Active — replaced the Daily's archive strip |
| [shop-coves.md](shop-coves.md) | Discovery / Content | Built — withheld from the header menu |
| [advice-coves.md](advice-coves.md) | Content / Editorial | Active — 10 shipped subjects (8 in four markets, 2 in three); more written over the API |
| [cove-planner.md](cove-planner.md) | Content / Operations | Active |
| [cove-curation.md](cove-curation.md) | Content / Operations | Active |
| [cove-writer.md](cove-writer.md) | Content / Operations | Active |
| [cove-automation.md](cove-automation.md) | Content / Operations | Active — `approve` ships off everywhere |
| [cove-entities.md](cove-entities.md) | Content / Discovery | Active |
| [seasonal-series.md](seasonal-series.md) | Content / Operations | Active |
| [cove-calendar.md](cove-calendar.md) | Content / Operations | Active |
| [prompt-bank.md](prompt-bank.md) | Content / Operations | Active — Cove kinds and the theme call |
| [house-style.md](house-style.md) | Content / Operations | Active — enforced at every write; production archive state unknown |
| [page-templates.md](page-templates.md) | Content / SEO | Active — replaces the copy bank |
| [email-templates.md](email-templates.md) | Content / Operations | Active — 4 of 9 mails editable |
| [product-cards-in-prose.md](product-cards-in-prose.md) | Content / Frontend | Active |
| [scheduled-writing.md](scheduled-writing.md) | Content / Operations | Active |
| [gift-personas.md](gift-personas.md) | Discovery / Content | Active — 10 planned per market in be-nl, nl-nl, en; not all published |
| [cove-scenes.md](cove-scenes.md) | Content / Frontend | Active — 38 scenes; personas, articles, and figures inside articles |
| [article-tables.md](article-tables.md) | Content / Frontend | Active — authored articles only; the builder is not told |
| [cove-subscriptions.md](cove-subscriptions.md) | Discovery / Email | Active |
| [editorial-api.md](editorial-api.md) | Content / Operations | Active |
| [content-promotion.md](content-promotion.md) | Content / Operations | Active |
| [config-contract.md](config-contract.md) | Core / Operations | Active |
| [wishlists.md](wishlists.md) | Wishlist / Alerts | Active — claiming needs an account |
| [pasted-links.md](pasted-links.md) | Wishlist / Ingestion | Active — links looked up (catalogue and connectors first), own photos, unknown barcodes; needs the `media_data` volume |
| [friends.md](friends.md) | Wishlist / Accounts | Active |
| [list-taxonomy.md](list-taxonomy.md) | Wishlist / Gifting | Phases 1–4 built; invitations retired 2026-09-14 |
| [saved-coves.md](saved-coves.md) | Coves / Wishlist | Active: save a Cove into My Coves, or copy it into a list of your own |
| [community-coves.md](community-coves.md) | Coves / Wishlist / Community | Active: an owner publishes a list as a public Cove; browsed on /coves, suggested by the Gift Finder, admin can hide |
| [list-surfaces.md](list-surfaces.md) | Wishlist / Gifting | Active |
| [ai-invariant.md](ai-invariant.md) | Core | Active |
| [legal-pages.md](legal-pages.md) | Compliance / Content | Active — fr/es untranslated |
| [cutover.md](cutover.md) | Operations | ✅ Done 2026-08-10 |
| [rebrand.md](rebrand.md) | Core / Operations | Active — done on the site 2026-09-05; third-party re-registrations unverified |

## Build phases

| # | Phase | Status |
|---|---|---|
| 0 | Foundation: schema, market routing, admin shell, deploy pipeline | ✅ Done |
| 0.5 | Staging deployed and verified at `staging.giftcoves.com` | ✅ Done 2026-08-07 |
| 1 | Ingestion & catalogue — Awin feeds, bol live, grouping, price history | ✅ Done |
| 2 | Search & offer comparison | ✅ Done |
| 3 | Accounts & wishlists, sharing, claiming, alerts, inbox | ✅ Done |
| 4 | Gift Whisperer + Serendipity Engine | ✅ Done |
| 5 | The Daily Cove — Daily Picks and buying guides merged into one daily edition | ✅ Done |
| 6 | *(folded into Phase 5)* | |
| 7 | Admin, SEO, cutover from v1 | ✅ Done — cutover executed 2026-08-10 |
| 8 | Deferred: the Amazon API connector, catalogue breadth, embeddings | |
| 9 | Gifting lenses: recipient linking, Secret Santa, co-givers, the quiz, occasion reminders | ✅ Done |
