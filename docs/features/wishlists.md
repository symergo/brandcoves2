# Wishlists, sharing and claiming

Lists for yourself and lists for other people. The second kind is the harder one, and it is the
reason most of the design decisions below look the way they do.

**Status:** Active (Phase 3)

---

## The rule everything else serves

> A gift list exists so the recipient does not learn what has been bought.

That makes claim state a *privacy* property, not a display preference. Two mechanisms enforce it,
because one of them will eventually be edited by someone who does not know the rule:

1. `WishlistItem::$hidden = ['claimed_by_hash']` — the column cannot leak through a
   `->toArray()`, an accidental `->with()`, or a debug dump.
2. [`WishlistController::show()`](../../app/Http/Controllers/WishlistController.php) builds the
   owner's item payload field by field and never adds a claim key. Not `false`, not `null` — absent.
   A `claimed: false` on every item is still a signal once one of them flips.

`SharedListController::show()` is the only place claim state is computed, and it returns `null` for
the owner rather than the truth. Tested in
[`WishlistTest::the_owner_never_sees_claim_state`](../../tests/Feature/WishlistTest.php).

### A bug this rule had, for a year

`shouldHideClaimsFrom()` took a `?User` and answered `false` for null — and
`SharedListController` passed `$owner->user`, which **is** null for an anonymous
owner. Since lists are anonymous-first by design, the ordinary case was the
broken one: build a list signed-out, share it, reopen your own link, see exactly
what has been claimed.

It now takes an `Owner` and compares both owner columns. Worth recording because
the signature looked right, the tests passed, and the failing case was the
common one rather than an exotic one.

### Claiming is gated on what a list is *for*

`is_gift_list` sat beside a nullable `recipient_id`, answering an overlapping
question and able to disagree with it — so claiming ended up gated on
*visibility*, and every shared list was claimable, including private research
about the person it was about. Replaced by `kind` (`mine` | `for_someone`); see
[gifting-lenses.md](gifting-lenses.md).

## Keeping a list requires an account

**Reversed deliberately.** This feature began anonymous-first, and the argument
below is a real one — a save is the moment somebody is most willing to act and
least willing to fill in a form. What it cost was the thing that matters more:
a list belonging to a cookie cannot be opened on a second device, does not
survive clearing the browser, and has no address a reminder could ever reach.
It looked like a feature and behaved like a draft.

Two things stay open to anonymous visitors, because requiring a login there
breaks the feature rather than improving it:

- **Claiming on a shared list.** That person followed a link once. Making them
  register to say "I'll get this" is how a gift list stops working as a
  coordination tool.
- **`/for/{token}`.** The token *is* the credential; describing yourself needs
  no account. Adding products does, and "This is me" is the short path.

`Owner`, `AnonymousIdentity` and `IdentityMerger` all remain — claiming, quiz
attempts and pick reactions still hang off a cookie identity, and a list built
before the rule changed still merges into an account at sign-in.

### The original reasoning, kept for the record



Saving a product does not require an account. `App\Support\Owner` unifies the two identities:

- signed in → `owner_user_id`
- otherwise → `owner_anon_id`, from the cookie identity set by `TrackAnonymousIdentity`

`Owner::scope()` returns `whereRaw('1 = 0')` when there is no owner at all, so a missing identity
yields an empty result rather than every list in the database. Failing closed matters more here than
anywhere else in the codebase.

The database enforces the same shape: `CHECK (num_nonnulls(owner_user_id, owner_anon_id) = 1)`. A
row owned by nobody is readable by nobody and deletable by nobody.

Requiring a login before someone can press Save is how you lose the visit — the person came to
compare a price, not to sign up.

## Snapshots, not references

`wishlist_items` stores `snapshot_title`, `snapshot_image_url`, `snapshot_price` and `snapshot_url`
alongside `group_id`. The feed can drop, rename or re-key a product tomorrow; the list must still
show what the person actually chose. The live group is joined for *current* price, so the owner sees
both "you saved it at €329" and "it is €279 now".

`group_id` is `nullOnDelete` for the same reason: losing the product must not lose the entry.

## The save control is a toggle, not a one-way door

`GET /{market}/list-options` takes an optional `group_id` and returns, per list, the `itemId` of the
row that product already occupies — `null` when it is not on that list. The picker draws a tick from
it, and pressing a ticked row deletes that item through the existing `destroy()` rather than a second
delete path with its own ownership check.

Before this the picker could only add. The two groups of lists sit a line apart in the menu, so
saving to the wrong one is easy, and undoing it meant leaving the product, finding the list, opening
it and deleting the row — at which point the product is gone from the screen. A control that reports
which lists hold something is also the only way to answer "did I already save this?" by looking
instead of by remembering.

The bookmark goes hollow only when **no** list holds the product. It is on your lists or it is not;
one of three lists letting go does not change that answer.

Saving names its destination — `lists.added_to`, "Saved to Camping". A save can land in the default
list, in one chosen from the menu, or in a list created in the same click, and "Saved to your list"
is equally true of all three, so it confirms nothing. Naming it is what makes the default worth
accepting without opening the menu.

## Claiming is a conditional UPDATE

```sql
UPDATE wishlist_items SET claimed_by_hash = ?, claimed_at = now()
WHERE id = ? AND claimed_by_hash IS NULL
```

Two people tapping "I'll get this" at the same moment is the expected case, not an edge case — a
link gets shared in a group chat and read by everyone at once. A read-then-write would let both win
and the recipient gets two of the same thing. The affected-row count decides the winner.

Undo is limited to the claimer's own hash and to 24 hours. A claim released weeks later means
nobody buys the thing and nobody knows.

`claimed_by_hash` is an HMAC of the claimer's identity under `CLAIM_HASH_SECRET`, so the stored value
identifies a claimer to *us* for the undo check without being reversible. Rotating that secret
orphans every existing claim — it is permanent in practice.

## The shared page renders what the controller sends

`SharedListController::show()` had been sending `progress` and a per-item `sent` since the strip was
specced, and `Lists/Shared` drew neither. Two consequences, both invisible from the server side:

- **Claiming was a dead end.** You said you would get it and then had nowhere to say you had. The
  endpoint (`POST /l/{token}/sent/{item}`) and the `marked_sent_at` column both existed; only the
  button was missing, so the progress strip could never finish.
- **No visitor could tell a list that was mostly spoken for from one nobody had touched**, which is
  the difference between choosing carefully and choosing quickly.

`progress` stays `null` for the owner rather than `0`. A count *is* claim state: the moment a zero
stops being zero they have learnt something. Absent, not zero — the same discipline as `claimed`.

`sent` is non-null only for the person holding the claim. Everybody else needs to know the item is
spoken for and nothing more.

## A list for a new person, from the lists page

`WishlistController::store()` accepts `new_recipient` and mints the person, exactly as
`WishlistItemController::store()` already did.

Without it the create form could only ever make a list for yourself. The recipient dropdown is drawn
from people you already have, and the only place to name a new one was the save picker on a product
card — so "a list for my sister" was reachable from a search result and not from the page called My
lists. The kind still follows from the recipient; there is no second switch that could disagree.

## Share links

`share_token` is a UUID, and `visibility` gates it independently. Turning sharing off has to actually
turn it off for someone who already has the link, so `findShared()` excludes private lists rather
than merely hiding the link in the UI.

A share link resolves from **any** market prefix. The link gets pasted into a message and opened by
someone whose browser lands them elsewhere; 404ing them would be a bug, not a feature. The list is
the same list wherever it is read.

---

## Alerts

Price-drop and back-in-stock watches, delivered to the in-app inbox.

### Signed-in only

Unlike lists. An alert fires days later and has to reach someone; a cookie identity has no delivery
address, and the cookie may well be gone by the time the price moves. Guests are redirected to the
login page **of the market they were browsing** — see the note on `redirectGuestsTo` below.

### Compliance is enforced server-side

Some affiliate programmes forbid a price-tracking feature. `AlertEligibility` resolves this per
product group, because a group holds offers from several sources with different rules:

| Method | Answers |
|---|---|
| `isEligible()` | can this product carry an alert at all? |
| `watchableOffers()` | which offers may the alert watch? |
| `excludedSources()` | which shops must the UI disclose as *not* watched? |

`AlertController::store()` checks eligibility before writing. Hiding the button is not enough — a
hand-built POST would otherwise create the alert anyway.

`RefreshWishlistedProducts::trackablePrice()` re-applies the same filter when deciding whether to
fire. This is the case a naive `min(price)` gets wrong: `product_groups.min_price` aggregates *every*
source, so an untrackable offer being cheapest would silently trigger a notification that should
never have existed. Tested in
[`AlertTest::a_drop_at_an_untrackable_source_does_not_fire_the_alert`](../../tests/Feature/AlertTest.php).

`excludedSources()` is surfaced in the UI as plain copy naming the shops. Promising "we'll tell you
when it drops" while quietly watching three shops out of four is a promise narrowed in secret.

### What counts as a price

Only `status = active` **and** `availability = in_stock`. Feeds routinely leave a stale low figure on
a listing that is sold out; a price you cannot pay is not a price.

### Firing once

An alert moves `active → triggered` when it fires. Without that transition, every scheduled run
re-notifies until the price recovers. Re-arming happens through the UI, which is the honest place for
it — the price went down and back up is a different event from the first drop.

A target price beats the baseline when set: someone who asked for "under €250" does not want to hear
about €5 off.

### Cadence

`RefreshWishlistedProducts` is scheduled twice daily at 05:20 and 17:20 — twenty minutes after
grouping, which is what turns a feed ingest into the aggregates an alert compares against. Running
more often than the underlying data changes would burn queries re-reading the same numbers.

It reads what ingestion already wrote rather than re-fetching, so the job costs a query per alert,
not a download.

### Uniqueness in the database

`updateOrCreate` is a read-then-write and will insert twice on a double-tap. A partial unique index
on `(group_id, user_id) WHERE user_id IS NOT NULL` settles it — two rows means two notifications for
one drop. Partial, because the email-only path can legitimately hold several rows for the same
product from different addresses.

---

## Notification inbox

One page for "what did I miss" and "what am I waiting for" — the same question asked at different
times.

The unread badge is shared from `HandleInertiaRequests` as a closure, so it costs nothing for the
anonymous majority and one index-only count for everyone else (`notifications` is indexed on
`(user_id, read_at, created_at)`).

Opening the page marks everything read. A separate "mark as read" button is a chore nobody performs,
and the badge then never clears.

---

## A bug this phase surfaced

Laravel's `auth` middleware redirects guests via `route('login')`. Every route here is prefixed with
`{market}`, and the exception handler has no market to supply — so an unauthenticated request to any
auth-only route returned **500 instead of a login page**. Fixed with `redirectGuestsTo()` in
[`bootstrap/app.php`](../../bootstrap/app.php), which resolves the market from the request path and
falls back to `Accept-Language`.

Worth recording because it was invisible until the first auth-gated route existed: Phase 3 added the
first one.
