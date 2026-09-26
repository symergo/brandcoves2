# Roadmap step 6: interoperable Coves

Strategy: [docs/strategy.md](../../docs/strategy.md), "Interoperable Coves". Written 2026-09-26.

## Goal

The same actions work on every Cove, whoever made it: **Add to Cove** and **Add to my Cove**
(already built), **Save this Cove**, **Follow Cove**, **Publish Cove**.

## Where we start

- Editorial Coves are `daily_pick_sets` rows (bigint id, `kind`, `slug`), served by
  `DailyCoveController`, `GuideController`, `GiftIdeasController`. Lists are `wishlists` (uuid,
  `share_token`, `market`, no slug).
- `ListAccess::scope()` (`app/Support/ListAccess.php:34`) already unions owned lists,
  collaborations, `list_opens` and `wishlist_shares` into "lists I can see"; the Lists index's
  `shared` view reads it. A saved Cove slots into that union.
- Notifications are a custom `notifications` table; `app/Services/Notifications/ListActivity.php`
  writes every per-list notification behind the claim-privacy gate. Digests follow
  `SendListPriceDigests` (one mail per person, one inbox row per list).
- `ListVisibility::public` can be stored (`WishlistController.php:889`) but no UI sets it, nothing
  shows it, and `/l/{token}` is `noindex` and disallowed in robots.txt.
- `User::displayName()` falls back to the part of the email before the `@`: fine for friends, a
  leak on a public page.
- Moderation precedent: Ask others (`TriageCommunityPost` job, `ModerationStatus`, Filament
  `CommunityPosts`).

## Design

### 1. Save this Cove

`saved_coves (id, user_id, wishlist_id uuid null, cove_id bigint null, follows boolean default
false, created_at)`, CHECK `num_nonnulls(wishlist_id, cove_id) = 1`, unique per user per target.
Cascades on the user, the list and the Cove.

- Button on `Lists/Shared.tsx` and on the editorial Cove pages (`Daily/Edition.tsx`,
  `Guides/Show.tsx`, `GiftIdeas/Persona.tsx`, shop and brand Coves). One component,
  `SaveCove.tsx`, next to the existing share menu.
- `ListAccess::scope()` gains saved lists, so a saved list shows under "shared with me" exactly
  like an opened one, with no new rules about who may see it. Saving a list never grants more than
  its link already does; if the owner makes it private, it disappears from the saver's view.
- Lists index gets a **Saved** view: saved lists and saved editorial Coves together.
- Anonymous visitors: the button opens the existing sign-in flow and completes the save after, the
  way `PendingSave` does for items.

### 2. Follow Cove

Following is saving with `follows = true` ("Follow" also saves). What a follower hears about:

- **A list** they follow: items added, in a daily digest, never claims (goes through `ListActivity`,
  new `coveChanged`), never for a private list.
- **An editorial Cove**: a guide, shop or brand Cove whose product set changed on rebuild (the
  builder already knows old and new picks; the diff goes in the payload). Following the **Daily
  Cove** means a new edition each day, in the app, next to the existing email subscription.

`SendFollowedCoveDigests` job, scheduled next to the price digest, one inbox row per Cove and one
email per person for those who opted into email. Rate: at most one notification per Cove per day.

### 3. Publish Cove

- **Who may publish:** lists of kind `mine` only. A `for_someone` or group list is about somebody
  else, who never agreed to be public.
- **UI:** `ListTools.tsx` sharing section gets a third state: Private / Link / **Public: anyone can
  find it**. Publishing asks for a public name if the user has none (`users.public_name`, new
  column; step 7 adds the handle on top) and shows what the public page will show.
- **Moderation:** `wishlists.published_status` (`pending`, `published`, `rejected`) plus
  `published_at`. Publishing sets `pending`; a triage job modelled on `TriageCommunityPost` screens
  title and notes; an admin approves in Filament. Until `published`, the list behaves as a link
  list. Only `published` lists are indexed, listed or in the sitemap.
- **URL:** a new public address, because `/l/` is disallowed in robots.txt and should stay so for
  link-only lists: `/{market}/{word}/{slug}` where the word is the market's search word for a wish
  list (`wish-list`, `verlanglijstje`, `liste-d-envies`, `lista-de-deseos`), resolved by a helper
  shaped like `SearchUrl`. `wishlists.slug` (unique per market) is set at first publication and
  never changes; a title change keeps the old slug.
- **SEO:** `PageMeta` title led by the searched word, inside the 48-character budget ("Emma's
  wish list: board games"); description from the list's intro or first items; `robots` index;
  sitemap entry for `published` lists; no hreflang alternates (a list lives in one market).
- **The public page** is the shared-list page in read-only mode for strangers: no claim buttons
  for anyone not signed in, claims shown to nobody but what `ClaimView` already allows, "Save",
  "Follow" and "Add to my Cove" present. A **Report** link writes to `feedback` with a
  `kind = list_report`.
- Unpublishing returns it to link-only; the public URL answers 410 so search engines drop it.

## Decide before building

1. Is review before indexing wanted, or is the triage job enough? Recommended: both at first.
2. The per-market URL word for public lists (the four above are suggestions).
3. May a public list show prices? Recommended yes, from the catalogue, with the usual Amazon rules.

## Files

- Migrations: `saved_coves`; `wishlists.slug`, `published_status`, `published_at`;
  `users.public_name`; `feedback.kind`.
- New: `SaveCoveController`, `PublicListController`, `SendFollowedCoveDigests`,
  `TriagePublishedList`, `SaveCove.tsx`, `Pages/Lists/Public.tsx`, a Filament "Published lists"
  review resource, a `PublicListUrl` helper.
- Change: `ListAccess`, `ListActivity`, `WishlistController` (index view, visibility), `ListTools.tsx`,
  `SitemapController` (entries; robots stays), the editorial Cove pages, `EditionBuilder` (report
  the pick diff).
- Docs: new `saved-coves.md`, `public-lists.md`; update `sharing.md`, `list-surfaces.md`,
  `seo.md`, `wishlists.md`, `INDEX.md`.

## Verification

- Feature: `SaveCoveTest` (list and Cove, private list vanishes, anonymous save completes after
  sign-in), `FollowCoveTest` (digest contents never mention a claim, one per Cove per day),
  `PublicListTest` (only `mine`, pending is noindex and absent from the sitemap, published is
  indexed, slug stable across renames, 410 after unpublishing, report writes feedback),
  `LocalisationTest` for the title budget in all four languages. Extend the claim-privacy tests to
  the public page.
- By hand: publish a list, approve it in the admin, find it in `/sitemap`, open it signed out.
