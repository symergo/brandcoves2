# Feature index

One `.md` per feature. Record *why* a non-obvious decision was made — the reasoning is the part that
cannot be recovered from a diff. Each doc's `date_added` says when it was new; this table says what
is true now.

| Feature | Area | Status |
|---|---|---|
| [market-routing.md](market-routing.md) | Core | Active — first visit gets a bar above the header, not a dialog, since 2026-09-26 |
| [not-found.md](not-found.md) | Core / Frontend | Active |
| [list-help.md](list-help.md) | Core / Frontend | Active |
| [auth.md](auth.md) | Core / Accounts | Active — Google needs credentials per environment |
| [user-admin.md](user-admin.md) | Core / Accounts / Admin | Active |
| [affiliate-settings.md](affiliate-settings.md) | Admin / Connectors | Active |
| [display-titles.md](display-titles.md) | Catalogue / Editorial | Active |
| [gift-tags.md](gift-tags.md) | Catalogue / Gifting / Editorial | Active |
| [localisation.md](localisation.md) | Core / Frontend | Active |
| [navigation.md](navigation.md) | Core / Frontend | Active — header is Find a gift, Discover ▾, My Coves and one country-and-language button (2026-09-26) |
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
| [product-identity.md](product-identity.md) | Catalogue | Active — merges and splits live in identity (aliases, overrides) since 2026-09-26 |
| [match-review.md](match-review.md) | Catalogue / Admin | Active — rules propose, a person decides every pair at /admin/match-review; no auto-merge yet |
| [product-signals.md](product-signals.md) | Catalogue / Discovery | Active — price range, saved by (from 5 people), found in Coves, related |
| [product-titles.md](product-titles.md) | Catalogue / SEO | Active |
| [search.md](search.md) | Search | Active — filters behind one button, Coves above the products, what others keep (2026-09-26) |
| [speed.md](speed.md) | Core / Frontend / Operations | Active — the 2026-09-27 speed audit follow-up: indexes and rewritten slow queries, translations sent once per language, page chunk preloaded, SSR clustered with a 2 s limit and off for signed-in visitors; shop and brand Coves store their link list at build, one cached shop list per market, admin search and badges off the slow paths; config and route caches at container start, a Caddyfile with immutable bundles and a JSON access log, a 30 s healthcheck, cookie-free machine-read routes, social-card 304s; Inter self-hosted, product pictures through our own WebP resizer, worker mode (Octane) prepared behind `OCTANE_WORKERS` and off |
| [image-proxy.md](image-proxy.md) | Frontend / Catalogue / Operations | Active — product pictures resized to WebP on our own signed `/img/{width}/...` address (search and brand cards, the product page), cached on the media volume; never Amazon; off with `IMAGE_PROXY_ENABLED=false` |
| [list-signals.md](list-signals.md) | Gifting / Catalogue | Active — crowd tags and product links from lists, nightly; ideas in the same spirit on shared wish lists |
| [crowd-picks.md](crowd-picks.md) | Gifting | Active — "chosen by others for someone like them": products on 5+ people's lists for the same kind of person rank higher in Find a gift and This or that; nothing shows until then |
| [offline-ideas.md](offline-ideas.md) | Gifting / Wishlist / Admin | Active — hand-typed items five people wrote, approved by a person, shown under Find a gift and This or that results |
| [intent-search.md](intent-search.md) | Search / Gifting | Active — the search box reads gift searches (who, interests, occasion, budget) |
| [gift-landing-pages.md](gift-landing-pages.md) | Gifting / SEO | Active — `/gift-ideas/for/{recipient}/{interest}`, recorded nightly when 8+ products fit; briefs stored and linkable; Cove plans can carry a brief |
| [persona-demand.md](persona-demand.md) | Gifting / Content | Active — gift searches counted as readings; nightly (06:30) drafts personas for readings nothing answers yet; drafts only |
| [persona-budgets.md](persona-budgets.md) | Gifting / Content | Active — tabs around 15, 40 and 100 under a persona's curated shelf, cached a day |
| [occasion-coves.md](occasion-coves.md) | Gifting / Content | Active — gifts per occasion at /gift-ideas/occasion/{slug}, a persona's page shape, own row, undated |
| [persona-top-ten.md](persona-top-ten.md) | Gifting / Content | Active — a weekly top 10 at the end of every persona, ranked by charts and wish lists; Mondays 08:20 |
| [has-everything.md](has-everything.md) | Gifting / Search / Content | Active — a brief flag: used up or done before more things; the search box reads the phrase in four languages |
| [search-urls.md](search-urls.md) | Search / SEO | Active — `/be-nl/zoek/term`, the market's word in the path |
| [seo.md](seo.md) | SEO / Frontend | Active |
| [page-titles.md](page-titles.md) | SEO / Frontend | Active |
| [analytics.md](analytics.md) | SEO / Compliance | Active — production only, behind a consent banner |
| [brand-mark.md](brand-mark.md) | Brand / Frontend | Active |
| [design-system.md](design-system.md) | Brand / Frontend | Active — tokens, Button, Badge, the navigation beam, shared rows, popups, headers and empty states; most call sites not yet migrated |
| [list-names-in-text.md](list-names-in-text.md) | Frontend / Wishlist | Active — a list's name in a sentence is `ListName` (kind icon, medium weight); bold in e-mail |
| [social-cards.md](social-cards.md) | SEO / Brand | Active |
| [brand-pages.md](brand-pages.md) | SEO / Discovery | Active |
| [brand-fill.md](brand-fill.md) | Catalogue / Ingestion | Active since 2026-09-30: an offer without a brand gets the known brand its title starts with; `bc:fill-brands` for the stored ones |
| [barcode-scanner.md](barcode-scanner.md) | Search / Mobile | Active |
| [popular-searches.md](popular-searches.md) | Search / SEO | Active |
| [crawlers-and-the-search-log.md](crawlers-and-the-search-log.md) | Search / SEO | Active |
| [search-help.md](search-help.md) | Search / Content | Active |
| [search-alerts.md](search-alerts.md) | Search / Alerts | Active — in-app only |
| [list-price-watch.md](list-price-watch.md) | Wishlist / Alerts | Active — one digest a morning |
| [feedback.md](feedback.md) | Core / Quality | Active |
| [contribute.md](contribute.md) | Core / Community | Active — `/contribute` ("Denk mee"): the feedback form, a voting board the owner runs at /admin (Community > Feature ideas), suggestions published by hand; a closable bar under the header on every page (2026-09-27) |
| [product-description.md](product-description.md) | Catalogue / Frontend | Active |
| [amazon-link-paste.md](amazon-link-paste.md) | Search | Active — ASIN redirect works for ASINs imported with a barcode (page import) |
| [amazon-search-cta.md](amazon-search-cta.md) | Search / Affiliate | Active on search, brand and product; `nl-nl` + both `be-*`, no tag for `en`/`es` |
| [find-a-gift.md](find-a-gift.md) | Gifting | Active — `/gift` as one flow: who first, then four ways (questions, This or that, a type, Ask others), the first three ending on one results page (`GiftResults`) that the landing pages draw too; thumbs up/down on each idea teach the engine per saved person and, from five voters, for everybody |
| [gift-whisperer.md](gift-whisperer.md) | Gifting | Active — the engine behind Find a gift; its questions are the first way in since 2026-09-26 |
| [taste-discovery.md](taste-discovery.md) | Gifting | Active — This or that: a dozen choices between products become a taste, a budget and ideas; can be kept on a person |
| [gift-gender.md](gift-gender.md) | Gifting | Active — "Man / Vrouw" as an optional profile question beside the age; products tagged `gender:` only when genuinely for one, and only those are left out |
| [taste-pairs.md](taste-pairs.md) | Gifting | Active — taste is the pairs of opposites only (to the owner, "the vibe"); the three-word Handig/Leuk/Mooi question and the values (sustainable/local/handmade) removed site-wide on 2026-09-29 |
| [my-taste.md](my-taste.md) | Gifting / People | Active — Mijn smaak: your own gift taste on your account, no budget; friends' searches for you start from it, as does Voor mezelf |
| [swipe-gifts.md](swipe-gifts.md) | Gifting / Lists | Active — Swipe through gifts: one product at a time, right onto the list, left to pass, no end but Stop; learns from what is liked |
| [taste-together.md](taste-together.md) | Gifting / Lists | Active — This or that played by several people about one person through a link; the giver sees a count and the combined result and can add it to the person |
| [gift-profile-card.md](gift-profile-card.md) | Gifting | Active — after This or that about yourself, a card with a link that opens Find a gift filled in; opt-in, removable, noindex |
| [discover-cove.md](discover-cove.md) | Core / Discovery | Active, rebuilt 2026-09-26: today's Cove, This or that, then six of each |
| [giftability.md](giftability.md) | Gifting / Catalogue | Active |
| [gifting-lenses.md](gifting-lenses.md) | Gifting / Core | Active |
| [secret-santa.md](secret-santa.md) | Gifting / Social | Active — chain repair built; year-on-year reuse open |
| [list-quiz.md](list-quiz.md) | Gifting / Growth | Active |
| [sharing.md](sharing.md) | Gifting / Growth | Active |
| [list-board.md](list-board.md) | Gifting / Coordination | Active |
| [occasion-reminders.md](occasion-reminders.md) | Gifting / Notifications | Active — four dates; windows editable in admin (friend birthdays on fixed ones); ideas about two weeks out; a per-person stop link since 2026-09-26 |
| [gift-history.md](gift-history.md) | Gifting / Notifications | Active — `/people/{id}`: what you gave (noted, and your own claims only), never suggested again, the next step after it; three ideas in the reminder about two weeks out |
| [recipient-birthday.md](recipient-birthday.md) | Gifting / Notifications | Active — day and month, never a year |
| [copying-items.md](copying-items.md) | Wishlist / Gifting | Active — copy only, never move |
| [serendipity.md](serendipity.md) | Discovery | Active |
| [recently-viewed.md](recently-viewed.md) | Discovery / Frontend | Active |
| [ask-others.md](ask-others.md) | Discovery / Community | Active. Since 2026-09-26 filled in from Find a gift and gift lists, always invited to on Discover, and sent to your people once published (two switches, one a day). Since 2026-09-27 two audiences: the community board, or only your people (friends and link holders, a link code, not read first, share popup after posting) |
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
| [list-budget.md](list-budget.md) | Wishlist / Gifting | Active — the budget is a list's, not a person's, since 2026-10-05; the person columns are dropped in a later deploy |
| [pasted-links.md](pasted-links.md) | Wishlist / Ingestion | Active — links looked up (catalogue and connectors first), own photos, unknown barcodes; needs the `media_data` volume |
| [friends.md](friends.md) | Wishlist / Accounts | Active — the page itself merged into My people (`/people`) on 2026-09-26; `/friends` redirects |
| [friend-invite-mail.md](friend-invite-mail.md) | Accounts / Email | Active since 2026-09-26 — inviting an address on My people emails it: 20 addresses a day, one email per address a month, never your own; "Wil je geen uitnodigingen meer ontvangen?" stops invitations to that address from anybody; only the separate "Meld als spam" button on its page counts a complaint (2026-09-27); 3 complaints stop a member's emails; admins see them under Community > Invitation complaints. Sent from a saved person, it links that person when it connects (2026-09-27). Its button signs a new invitee straight in, no second email: a single-use token, 14 days, never an existing account (2026-09-27) |
| [my-people.md](my-people.md) | Accounts / Gifting | Active — `/people`: saved people and friends on one list, nearest date first, a line of what you know under each name, one button (Cadeau vinden) and a Meer menu; add someone or invite; `/friends` redirects here. `/people/{id}` is a profile: what you know (editable), their wish lists, your lists for them, "Samen met" for a friend (their lists for others, group gifts, Secret Santas; never a list about you, never the draw), gift history, rename and delete. "Nodig uit op GiftCoves" on a saved person with no account (2026-09-27): the same invitation, and the saved person is linked to the account when it connects (at once, or at sign-in via `friend_invites.recipient_id`) |
| [wish-list-for-my-people.md](wish-list-for-my-people.md) | Wishlist / Gifting | Active — "Visible to my people" on a wish list: friends see it and pick from it for a list about you; on for new wish lists, off for older ones |
| [list-taxonomy.md](list-taxonomy.md) | Wishlist / Gifting | Phases 1–4 built; invitations retired 2026-09-14; the views became sections of one page 2026-09-26 |
| [saved-coves.md](saved-coves.md) | Coves / Wishlist | Active: save a Cove into My Coves, or copy it into a list of your own |
| [save-button.md](save-button.md) | Wishlist / Coves / UI | Active: one Save button and one panel for products and Coves; the "▾" is gone |
| [community-coves.md](community-coves.md) | Coves / Wishlist / Community | Active: an owner publishes a list as a public Cove; browsed on /coves, suggested by Find a gift, admin can hide |
| [list-surfaces.md](list-surfaces.md) | Wishlist / Gifting | Active — your own list opens on its items; Share plus a More menu in the header, a "⋯" per item; My Coves on one page and one word per list kind (2026-09-26) |
| [inline-product-search.md](inline-product-search.md) | Wishlist / Gifting / UI | Active since 2026-09-27 — one inline search (field, barcode, rows) shared by the list's add panel, `/for/{token}` (now the add panel itself), Find a gift, a shared list's suggest and Ask's answer picker |
| [one-step-list.md](one-step-list.md) | Wishlist / Gifting | Active since 2026-09-26: a list in one question (who for), occasion and sharing moved to the list page; replaces the three-step wizard |
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
