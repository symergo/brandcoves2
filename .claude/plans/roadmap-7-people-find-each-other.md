# Roadmap step 7: people find each other

Strategy: [docs/strategy.md](../../docs/strategy.md), "People". Written 2026-09-26.
Builds on step 6 (public lists, `users.public_name`, saved and followed Coves).

## Goal

People have a public face, can follow each other, find other people's Coves, and recommend
products; what people save and share starts to count in ranking, without ever overriding relevance.

## Where we start

- `users` has `name`, `avatar_url`, no handle. `displayName()` falls back to the email's local part.
- Following is built and unused: `user_follows`, `user_blocks`, `App\Services\Social\FollowGraph`
  (`follow`, `unfollow`, `block`, `eitherBlocks`, `friendsLists`), no route, no UI, no tests.
- Friends (`friendships`) are symmetric and private; following is one-way and public. They stay
  separate: a friend sees your link-shared lists, a follower sees only what you published.
- `events` stores only `click_out`, pruned after 90 days. No save or share events. GA4 is
  client-side only.
- No self-service account deletion: accounts are deleted from the admin.

## Design

### 1. Handles and profiles

- `users.handle` (unique, lowercase, 3-30 characters of `a-z0-9_-`, a reserved list: admin, api,
  giftcoves, the market codes, every first path segment the router uses). Chosen, never derived
  from the email. `public_name` from step 6 is the display name beside it.
- `/{market}/u/{handle}`: public name, avatar, published lists in this market, the user's
  recommendations (below), follower count, Follow button. `noindex` until the profile has at
  least one published list, so empty profiles never reach search engines.
- Settings page: handle, public name, avatar, "who can follow me" (anyone / nobody), block list.

### 2. Following people

Wire `FollowGraph` behind `FollowController` (follow, unfollow, block, unblock), throttled. Tests
for the graph itself first: it has none. What following gives you:

- A **From people you follow** feed on the Lists index and in the inbox: their newly published
  lists and recommendations. `friendsLists()` becomes `followedActivity()` and also covers
  lists published through step 6's `published_status`, not just `visibility = public`.
- A follow creates a notification for the followed person ("X follows you"), at most one a day,
  batched.

### 3. Discovery

- `/{market}/coves` gains a **From the community** band: published lists, ordered by the score in
  section 5, never by commission. Editorial Coves keep their own bands.
- The product page gains **In these Coves**: published lists and editorial Coves that hold this
  product, so a product leads to people and people lead to products.

### 4. Recommendations

No new object. "Recommend" on a product adds it to the user's **Recommendations** list, a
`mine` list created on first use and published with the user's approval, shown on their profile.
One word, one list, the same rules as every public list (moderation, claims hidden, report link).

### 5. Saves and shares as signals

- New event kinds in `events`: `item_saved`, `cove_saved`, `cove_shared` (from the share menu),
  `cove_followed`. Anonymous ids are hashed as today and follow the 90-day prune.
- A nightly job aggregates them into `product_groups.saves_30d` and `wishlists.engagement_30d`
  (counts only, no people), so the ranking reads a number and the raw events can expire.
- **Ranking rule** (written into `search.md`): relevance stays first. Saves are a tie-breaker in
  search (after text match, before merchant count), a demand signal in `SuggestionEngine` beside
  chart demand, and the main order for community discovery. Commission is not a signal anywhere,
  the promise in the strategy.

### 6. Leaving

Self-service account deletion (settings → delete account → confirm by email): deletes the user,
cascades lists, photos (step 1), follows, saves; published lists go with them (410). Needed before
profiles make people visible, and required by GDPR anyway.

## Decide before building

1. Can anyone follow anyone, or only people who allow it? Recommended: anyone, with the setting to
   switch it off.
2. Is `/u/{handle}` per market (as every route is today) or one profile across markets showing
   lists from all? Recommended: per market, matching how lists are scoped.
3. Does the **In these Coves** block count toward the product page's title/description budget?
   No; it is body content. Confirm the page does not get too long on mobile.

## Files

- Migrations: `users.handle`, `users.followable`; `product_groups.saves_30d`;
  `wishlists.engagement_30d`.
- New: `ProfileController`, `FollowController`, `AccountDeletionController`,
  `app/Jobs/AggregateEngagement.php`, `Pages/Profile/Show.tsx`, `Pages/Settings/Profile.tsx`.
- Change: `FollowGraph` (activity query, tests), `User` (`displayName` never uses the email on a
  public surface), `CovesController` + `Coves` page (community band), `ProductController` +
  `Product.tsx` (In these Coves), `SearchService` (tie-break), `SuggestionEngine` (demand),
  `ShareMenu` (share event), `PrunePersonalDataCommand` (new event kinds), `SitemapController`
  (profiles with a published list).
- Docs: new `profiles.md`, `following.md`, `engagement-signals.md`, `account-deletion.md`; update
  `friends.md` (friends vs followers), `search.md` (ranking rule), `privacy` page copy, `INDEX.md`.

## Verification

- Unit: `FollowGraphTest` (self-follow, blocks both ways, unfollow), handle validation (reserved
  words, route collisions).
- Feature: `ProfileTest` (noindex until a published list, never shows an email fragment),
  `FollowTest`, `CommunityBandTest` (order by engagement; commission changed in the data does not
  change the order), `AccountDeletionTest` (everything personal gone, public URL 410),
  `EngagementAggregateTest`, prune test for the new events.
- The ranking change touches a shared service: run the search and suggestion tests in full, and
  `composer test` before the commit.
