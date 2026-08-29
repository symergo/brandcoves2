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

### Claiming is no longer `mine`-only, 2026-08-29

A `for_someone` list is claimable once somebody else is on it, and its owner sees the claims —
because there the owner is a co-giver rather than the person being surprised. The full table, the
`ClaimVisibility` setting and the `$isOwner` split are in
[list-taxonomy.md](list-taxonomy.md#claiming-on-a-gift-list-and-the-same-inversion-a-second-time).

**Handover is where that change bites, and its docblock said the opposite.** It read "there are no
claims to worry about: a `for_someone` list is not claimable in the first place". Now:

- **The claim hash stays.** A sibling may already have bought the thing; releasing it sends a second
  person to the shops. The new owner never learns of it — the list is `mine` now, so claims are
  hidden from them absolutely.
- **The name and the setting are reset.** A claimer typed their name for a small audience of
  co-givers plotting a surprise; the list is now a wish list its owner may share with anyone, and
  consent to the first audience is not consent to the second.

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

## The confirmation nobody could read

Added 2026-08-29. The paragraph above was true, and the person saving never saw it.

`store()` answered `back()->with('success', …)`, and `FlashMessage` draws `flash` in the layout, in
the normal flow, at the top of `<main>`. Saving happens on a product card, and the commonest product
card is thirty rows down a search grid — where `preserveScroll` correctly keeps you in place and the
confirmation therefore rendered **off-screen**. Worse: being in the flow, it *inserted* a block at
the top of the document while the scroll offset was held, so the grid jumped down under the cursor at
the exact moment of a successful tap. The one line of copy written specifically to make the default
destination trustworthy was the line the delivery made unreadable.

Three things changed together, and they are one change rather than three:

- **`store()` and `destroy()` answer JSON when the caller asks for it** (`expectsJson()`), keeping
  `back()` for form posts — `ManualItem` and the list pages submit through Inertia and do want the
  page back. A bookmark tap had been rebuilding every prop on the page: a forty-result search re-run
  on the server to move one row.
- **`SaveToList` posts directly** (`resources/js/http.ts`), which is the pattern `Daily/Edition`,
  `Discover` and `MarketSwitcher` already use — CSRF token from the document head, `Accept:
  application/json`. That header is load-bearing twice: it picks the JSON branch, and it turns
  Laravel's guest redirect into a 401 rather than a 302 that `fetch` follows silently and reports as
  a success.
- **`SaveToast` reports it**, fixed to the viewport, six seconds, paused on hover or focus.

**Undo is why the endpoint answers with a row.** A flash string can name a list and can never name
the row it just created. Undoing a save previously meant reopening the picker and finding the ticked
line — which works, and requires knowing that the picker reports membership at all. The mistake being
recovered from is saving to the list one line above the one you meant, and the moment to catch it is
the second afterwards, on the confirmation that has just named the wrong list. Undo calls the
existing `DELETE /list-items/{item}`: no second delete path, and the ownership check is the one that
was already there.

`FlashMessage` is unchanged and keeps every other outcome. A claim somebody else won, or a quiz
refused for being too short, is about the page, and prominence at the top is right for those. A save
is about the thing under your finger.

**The card variant now saves on the first press.** It used to open the picker in both states, on the
stated grounds that saving somewhere unnamed and making people undo it is worse than asking first.
That was correct while it held; the toast names the list and carries the undo, so the premise is
gone. What remained was the real cost — the same bookmark meaning "save it" on a product page and
"ask me where" on a grid is not something anybody learns, it is something they get wrong. Both
variants now follow one rule, **not saved → save; saved → open the picker**, and the card keeps a
narrow chevron so that filing straight into a named list does not first cost a save into the wrong
one.

### Two smaller things that were silently broken

- **`place()` clamped `left` and never `top`.** `top` was `box.bottom + 6` with no bound at all, so a
  card in the bottom row of a grid opened a three-section panel below the fold, where it could be
  neither read nor reached. It now flips above the trigger when there is more room there, and caps
  its height either way so twenty lists scroll rather than run off the screen. Below `sm` it is a
  bottom sheet: a 288px popover anchored to a 36px button is a desktop shape, and on a phone every
  card is at both edges of the viewport at once.
- **Neither `save()` nor `remove()` had an error branch.** A 403 or a 422 re-enabled the button and
  did nothing else, which is indistinguishable from a control that does not work — on the one control
  people press at the moment they have decided something. Failures now surface in the toast, and the
  optimistic bookmark rolls back. Relatedly, the `/list-options` fetch fell back to `{lists: [],
  recipients: []}`, so a dropped connection was indistinguishable from "you have no lists" — and that
  second reading invites somebody to create a duplicate of a list they already own. It has its own
  error state, with a retry.

## Signing in without losing the product

Added 2026-08-29. "Keeping a list requires an account" is unchanged. What that decision never settled
is what happens to the product in somebody's hand at the moment they are asked to sign in — and the
answer was that it is thrown away.

`requireAccount()` navigated to the login page **client-side, before any request reached the
server**, so Laravel never recorded an intended URL. Signing in landed the visitor on My Lists, with
an empty list, on a page they had not asked for, having forgotten what the product was called. The
reversal to accounts-only was argued carefully and is still right; it simply accepted this loss
rather than solving it, at exactly the moment a person is most willing to act.

`App\Services\Wishlist\PendingSave` stashes the save in the session and sets `url.intended`, so both
`MagicLinkController` and `GoogleController` return the visitor to the product with no change to
either — they already end in `redirect()->intended(…)`. `ReplayPendingSave` listens for `Login`, for
the same reason [`ClaimListInvitations`](../../app/Listeners/ClaimListInvitations.php) does: a save
that completes on the magic link and not on Google is a bug visible only to whichever half of people
pressed the other button.

Four rules, each of which is the interesting part:

- **One intent, not a queue.** Press save, get asked to sign in, sign in. That is the whole story. A
  queue means deciding what to do with six intents gathered over a week of browsing, and replaying
  five products somebody has forgotten choosing is a worse outcome than dropping them.
- **One hour, single use.** A save replayed days later — plausibly on a shared machine, plausibly by
  somebody else — is not what anyone asked for, and a gift list is the wrong place to find an item
  you did not put there. It is cleared *before* it is applied, so a replay that throws cannot leave
  one behind to fire on the next sign-in.
- **`return_to` is checked, not trusted.** It ends up in `url.intended`, which is where a freshly
  authenticated person is sent. An unchecked value there is an open redirect wearing a login page as
  a costume. A path on this host or nothing: `//host` is a protocol-relative URL rather than a path,
  and a backslash is refused because browsers have historically normalised it to a slash.
- **No `wishlist_id`, and no `manual`.** A pending save lands in the default list; carrying a chosen
  destination across a sign-in would mean checking ownership of a list against an account that did
  not exist when it was chosen. And a hand-written wish is typed on a list page, which is behind
  `auth` already — accepting one here would be a free-text channel with no owner.

The snapshot fields stay hints on this path exactly as on the ordinary one, so a stale intent naming
Amazon cannot smuggle a mirrored title and price into the catalogue: `ItemSaver::saveExternal()` is
still the only thing deciding what may be stored (invariant #6).

`lists.sign_in_hint` said *"Your lists live in this browser right now"* — left over from
anonymous-first, describing a state that has not been reachable since the reversal. Rewritten
forwards, in all four languages.

## Where you can keep something, and where you could not

Also 2026-08-29. Seven surfaces rendered products and offered no way to keep one. Each is a place
somebody sees a thing they want and has nowhere to put it, which is the same failure the results
grid had before it gained a bookmark.

| Surface | Why it was worth fixing |
|---|---|
| Home, today's finds; Discover Cove finds and surprises | Four tiles on the two pages most likely to be somebody's first visit |
| Search, the **by shop** lane | The grid saves because it is a `ProductCard`; this view is a bespoke row and had nothing — so changing how you *look* at the same results silently removed the ability to keep one |
| Ask, answer picks | Somebody recommended this to a stranger and the stranger could not keep it |
| A shared list, as a visitor | A page full of things chosen for a person you also know, and no way to note one down |
| Secret Santa, your giftee's wishes | Claiming is a commitment to the group; keeping a note to yourself should not require making one |
| Brand pages, live offers | See below |

In each case the control sits **outside** the wrapping anchor and above it on the z-axis. A tile is
one big link, and a button nested inside a link is not a button — the anchor takes the click. That is
the same constraint `ProductCard` documents for its stretched link.

**The shared list and Secret Santa needed `groupId` in the payload, and it discloses nothing.** `url`
in the same payload has always been `p/{group_id}/{slug}`, so the id was already there; it was simply
not in a form the save control could read. The act it enables reads the *viewer's* lists and writes
to the viewer's list — the owner's list is not touched and learns nothing, so this is not a claim and
invariant #4 is not involved. Pinned by
`a_visitor_saving_from_a_shared_list_tells_the_owner_nothing`. The control is hidden from the owner
of a list, where everything is already theirs and it would only confuse.

**The live-offer save was built and reachable from nothing.** `store()` has accepted `source` +
`external_id` since live results became searchable, and `ItemSaver::saveExternal()` has decided per
source what may be stored — but `BrandController::liveCard()` emitted neither field, so the whole
external path had no UI at all. The same shape as the progress strip, the suggest button and the
pledges: complete, tested, unreachable. `liveCard()` now carries both. Passing the snapshot fields
from the client stays safe because the server discards them for a source it may not mirror, so an
Amazon offer keeps the decision and nothing else (invariant #6).

## Files

- `app/Http/Controllers/WishlistItemController.php` — `report()`, `saved(?list=)`, `find()`
- `resources/js/Components/AddProduct.tsx` — the one control on the list page
- `app/Services/Wishlist/ItemSaver.php` — `saveGroup()` takes the owner's wording
- `tests/Feature/AddProductTest.php`
- `app/Http/Controllers/SaveIntentController.php`, `app/Services/Wishlist/PendingSave.php`,
  `app/Listeners/ReplayPendingSave.php`
- `resources/js/http.ts` — one CSRF `fetch`, written once
- `resources/js/Components/SaveToast.tsx`, `resources/js/saveToast.ts`
- `resources/js/Components/SaveToList.tsx`, `resources/js/savedItems.ts`
- `tests/Feature/SaveToListTest.php`, `tests/Feature/PendingSaveTest.php`
- See [list-taxonomy.md](list-taxonomy.md#filling-one-list-rather-than-saving-one-product) for the
  adding mode

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

### The same bug again: suggestions had no sender

"I think you would like this" is the empty-list problem attacked from the opposite side to the quiz —
rather than persuading you to fill in your own list, the people who know you fill it in for you.
`POST /l/{token}/suggest` shipped complete, with its guards and three passing tests. The owner's side
shipped complete: pending suggestions sit at the top of `ListTools`, with **Add it** and **No
thanks**.

**Nothing on any page could send one.** No component posted to that route, and
`suggestions.suggest` — "Suggest something" — sat translated into four languages, rendered nowhere.
The tests passed throughout because they `POST` to the endpoint directly, which proves the endpoint
is right and says nothing about whether a human can reach it. Same shape as the Santa invite
answering 405 and the progress strip above.

`Lists/Shared` now carries a search box and a result grid below the list. Three decisions:

- **Below the items, never above.** Somebody arrived to see what this person wants. A search box
  first answers a question they have not asked, and a thin list — the case the feature exists for —
  is exactly the one where they scroll far enough to reach it anyway.
- **A `GET` back to the same URL with `?q=`,** re-rendering the page with `results`, rather than a
  second endpoint. One route means one place the share token is resolved and gated.
- **`results` is `null` before a search and `[]` after one that found nothing.** Collapsing them
  makes the first visit look like a failed search.

`canSuggest` mirrors the three conditions the endpoint enforces — not the owner, a claimable list, an
identity that exists — so the control is absent wherever the POST would be refused. Mirrored, never
trusted: hiding a button stops nobody hand-building the request. No account is needed, for the same
reason claiming needs none.

**Two things this surfaced, both invisible while the endpoint was unreachable:**

`ItemSaver::saveGroup()` is an `updateOrCreate` on `(wishlist_id, group_id)` and
`SuggestionController` nulls `accepted_at` immediately afterwards — so suggesting a product the owner
**already had** would take a real item off their list and turn it back into a pending suggestion,
carrying its claim with it. The owner watches something they chose disappear; whoever claimed it is
still on the hook for a row nobody can see. Every existing test posts a group that is not on the
list, because that is what the feature is *for* — and a duplicate becomes the ordinary case the
moment a visitor can search, since the obvious thing to suggest is the obvious thing to already own.
Now refused with "That one is already on the list", which reveals nothing: the item is on the page in
front of them.

`search_log` feeds the related-search chips on public narrative pages and the demand signal behind
the guide queue. Running the shared-list search through `SearchService` unchanged would push terms
typed inside one named person's gift list into public content — "engagement ring" resurfacing as a
suggested search somewhere else. `SearchQuery::$logged` defaults to true and this one caller opts
out; `withBrands()` and `withTerm()` carry the flag rather than re-defaulting it, so a narrowed or
rewritten query cannot start logging behind you.

**The `note` split, settled 2026-08-16.** The field was accepted, stored and sent to the owner, and
rendered nowhere — so a note somebody wrote reached the payload and vanished. It is now read on the
owner's side and written by the owner only (`ManualItem withNote`).

The deferral was always about *writing*, not about the field: free text from a stranger on an
unauthenticated page is a moderation surface. An owner typing "size M, in blue" on their own list is
not one by definition. The visitor-side input stays unbuilt, and the considered version of "let
strangers write things here" is [ask-others.md](ask-others.md), which has a triage job and a review
queue behind it.

## One button, because it was always one intention

Added 2026-08-29. The list page carried two controls: **Find things to add**, which navigated away to
a search, and **Add something yourself**, which opened a form for the case where the catalogue does
not have it. `Product toevoegen` is both.

**The split was ours, not the visitor's.** Choosing between those two buttons means answering, before
typing anything, whether we happen to stock the thing you are thinking of — and that is a question
only we can answer. The panel answers it by searching while they type, and keeps the hand-written
path open the whole time.

Three ways out, one control:

- a catalogue result, with its price, link and offer comparison intact;
- a live result from a source we do not mirror;
- something typed by hand, **reachable without searching first**.

That last point is the one worth defending. Offering the manual path only after a search has come
back empty makes it a consolation prize, and it is not: a voucher for the climbing gym, or one
particular edition of a book, is not a failed search. It sits in the panel from the moment it opens,
and the search term prefills its description — somebody typed it because it is what the thing is
called.

### The wording belongs to the owner

`ItemSaver::saveGroup()` now takes a `$title`. A feed title is written for a search engine — *"Merk
XY-3000 draadloze koptelefoon met ANC, zwart, 2024"* — and a list is read by a person, sometimes one
choosing a present under time pressure. *"De koptelefoon die ik wil"* is more use to them.

This does not contradict [snapshots, not references](#snapshots-not-references). That rule is about
an entry not rewriting *itself* when a feed changes; a title somebody typed on purpose is the same
kind of fact as the note beside it. `group_id` is untouched, so the price, the link and the offer
comparison all still come from the real product — only the words change.

**A bug this surfaced, which predates it.** `saveGroup()` is an `updateOrCreate`, and every save from
a product card posts no note at all — so re-saving something you had annotated **erased the
annotation**, silently. The title would have gained the same defect the moment it became editable.
Both are now kept when the incoming value is null, pinned by
`saving_it_again_does_not_undo_the_wording`.

### Where the title field is absent, and why

For a source that may not be mirrored, `WishlistItem::rendersLive()` re-fetches title and price at
render — so a title typed here would be discarded without saying so. `/list-search` returns
`storable` per live row, and the panel offers the note instead, with a line naming the shop the title
comes from. A field that silently does nothing is worse than an absent one (invariant #6).

### The search inside the list

`GET /{market}/list-search` — `auth`, `throttle:60,1`, refusing terms under two characters, and
**run on Enter rather than as you type**. All of it bounds the same thing: the live half of a search
costs real requests to bol and Amazon, and a typeahead turns one intention into eleven searches.
`SearchService` caches the mirrorable connectors; the throttle, the minimum and the explicit submit
are what bound the one it cannot.

Submitting also reads better. A product search is a considered act — people type two or three words
and then look — and results reshuffling under a half-typed word move the row somebody was reaching
for. The field is a `<form>` so Enter submits with no key handler, and it carries a named **Search**
button beside it, because nothing else on screen would say that typing alone does not search.

Two defaults are overridden, and both would have been wrong quietly. `discountedOnly` defaults to
**true**, which would have limited a gift list to whatever happens to be reduced today. And `logged`
is turned off: `search_log` feeds the related-search chips on public pages and the queue that decides
which guides get written, so *"verlovingsring"* typed while filling a list about one named person
does not belong in it — the same opt-out, for the same reason, as the search box inside a shared
list.

`ManualItem` still exists and is still used on `Lists/Shared`, where a visitor suggests something
into somebody else's list. That is a different act with different rules, and folding it into this
panel would have put an owner-only note field on an unauthenticated page.

## Something we do not sell

The catalogue is not the world. A voucher for the climbing gym, the local bike shop, one particular
edition of a book — a list that cannot hold those is a list with the real present missing, and the
honest workaround was to leave it off.

`ItemSaver::saveManual()` writes a row with `source = manual`, no `external_id` and no `group_id`.
The schema already allowed it: `wishlist_items_identifiable` was widened in
`2026_08_09_001000` to accept "something a person wrote down", and this is the path that finally
writes one. No `updateOrCreate` — there is no upstream identity to collide with, and two entries
called "a nice scarf, dark green" are two wishes rather than a double-tap.

**One form, two endpoints, because it is one act.** `Components/ManualItem` is used by the owner on
`Lists/Show` and by a visitor on `Lists/Shared`. The owner's post lands on the list; the visitor's
goes through `SuggestionController` and lands pending, with the same accept/dismiss row as any other
suggestion. Neither page needs to know how the other behaves, and a second copy of the form would
drift the moment one of them gained a field.

Euros in the box, cents on the wire (invariant #7), converted in the component — and a comma is
accepted, because half our markets write €12,50 and typing it the way you say it should not be a
validation error.

See [gifting-lenses.md](gifting-lenses.md) for why nothing is fetched from the link, why there is no
image, and where the `https:`-only rule is enforced — three times, on purpose.

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

## The registry, which you could fill in and nobody could use

> **The control is called "Special occasion" as of 2026-08-29**, in every language — `registry.badge`,
> with `registry.heading` and `registry.none` moved to match. *Registry* named the artefact; the
> button attaches an occasion and a date to a wish list you already have, which is what the new label
> says. Nothing below changed: the columns, the gate and the two readers are exactly as described.
> See [list-taxonomy.md](list-taxonomy.md#registry-became-special-occasion).


Added 2026-08-16. `event_type`, `event_date` and `delivery_address` were stored and read back **to
the owner alone** — `SharedListController::show()` emitted none of them in any branch — so a registry
was a form with no reader. Two pieces of copy and the migration's own comment had promised otherwise
since the day it shipped.

**The occasion and the date are not gated.** They are why the list exists ("Wedding, 14 June") and
belong to everybody holding the link. **Only the address is**, which is exactly what
`registry.address_hint` says: it appears once you have claimed something.

The gate is decided in one place, immediately after the viewer's claim hash:

```php
$hasClaimed = ! $isOwner && $claimable && $hash !== null
    && $list->items()->where('claimed_by_hash', $hash)->exists();
```

It reads **their own** hash, so the answer tells them nothing about anybody else, and `! $isOwner`
short-circuits it so it can never become a second route to "has anybody claimed" — which is
`progress`, and is already withheld from the owner. The dangerous variant is
`whereNotNull('claimed_by_hash')`; do not write it.

`delivery_address` is an encrypted cast, so reading it there **is** the authorised disclosure. There
are now exactly two readers in the codebase — the owner's own page behind `ListAccess::isOwner()`,
and this one behind `$hasClaimed`. There is no third.

Releasing a claim closes the gate again on its own, because `WishlistItem::release()` nulls the hash.
Nothing revokes the address explicitly and nothing should have to;
`releasing_a_claim_takes_the_address_away_again` pins it.

> The test that used to be called `a_visitor_never_receives_the_delivery_address` is now
> `a_visitor_who_has_claimed_nothing_never_receives_the_delivery_address`. Its body is unchanged. It
> stopped being a statement about the feature and became one half of a distinction.

### An occasion stopped being a registry, 2026-08-29

The panel was gated `access.isOwner && list.kind === 'mine'` — so an occasion could only be set on a
wish list of your own. Everything underneath it was already kind-agnostic: the column, the
`update()` validator, and `SharedListController`'s block. A birthday on a list about your father was
**storable, renderable, and had nowhere to be typed**. Same shape as the pledges and the progress
strip — complete, and unreachable.

The gate is now `access.isOwner`, and the split that makes that safe is on the model:

| | Answers | Used by |
|---|---|---|
| `hasOccasion()` | does this list say what it is for? | the occasion block, any kind |
| `isRegistry()` | `hasOccasion()` **and** `kind === Mine` | the delivery address, and only that |

**One method answering both is how the address gate widens.** `delivery_address` is the owner's
home, encrypted, disclosed to anybody who has claimed something — and it is only ever appropriate on
a list belonging to the person the parcel is for. A gift list about somebody else may carry an
occasion and must never carry an address. The field is hidden for those kinds, and the panel then
**omits the key from the PATCH entirely** rather than sending an empty string: `FormData.get()`
returns null for an absent input, so posting it unconditionally would clear a stored address every
time somebody edited the occasion.

The payload key is `occasion`, not `registry`, for the same reason — `registry` on a group list
would be a lie about what the block is.

#### The enum was the registry's five, and the calendar was missing

Wedding, baby, housewarming, birthday, other. Christmas — the biggest gifting occasion there is —
had to be filed under "Something else". `EventType` now carries fourteen, and
`2026_08_29_000100_an_occasion_is_not_only_a_registry` widens the CHECK constraint to match.

**That migration writes its values out literally, and the original does not.**
`2026_08_09_002100` builds the constraint by calling `EventType::values()`, which means it describes
a *different schema every time the enum changes* — replay it on a fresh database today and you get
fourteen values where every real database has five. That divergence is the reason the second
migration exists, so repeating the mistake in it would be strange.

The consequence is that **adding a case to `EventType` now needs a migration**, and forgetting is
silent: valid PHP, a validator that builds its `in:` rule from the same enum and passes it, and a
dropdown option that throws a `QueryException` the moment somebody picks it. Nothing else catches
it, because every other test picks `wedding`.
`every_occasion_the_enum_offers_is_one_the_database_accepts` is that catch — asserted per case, and
observed failing against a deliberately unmigrated case before being kept.

---

## Money on a list, which had a write path and no read path

Added 2026-08-16. `GiftPledge`, `GiftPledgeController`, both `/l/{token}/pledge/{item}` routes and
ten `pledges` copy keys in four languages shipped complete and were wired to **nothing**: no React
file referenced a pledge, and no controller ever loaded `$item->pledges`, so the feature could
neither be used nor seen. The Gift Cove advertised it the whole time
(`gift_cove.collab_body`, "pledge towards one bigger present").

Both halves now exist — `Wishlist::allowsContributionsFrom()` for the write, and
`app/Services/Wishlist/ContributionView.php` for the read — and the privacy rule **inverts between
two kinds of list**, which is the part worth reading before touching either. The full table and the
three things that are load-bearing about it are in
[list-taxonomy.md](list-taxonomy.md#built-2026-08-16-and-what-the-shape-turned-out-to-be).

The one-line version: on a `mine` list a pledge is claim state and its owner is told nothing at all;
on a `group` list the owner is the organiser rather than the recipient, so they see who put in what,
and the other members see only the total and their own share.

`gift_pledges.display_name` has been required on write since the table shipped and was read by
nothing until now — `ContributionView::breakdown()` is its first and only reader.

---

## A bug this phase surfaced

Laravel's `auth` middleware redirects guests via `route('login')`. Every route here is prefixed with
`{market}`, and the exception handler has no market to supply — so an unauthenticated request to any
auth-only route returned **500 instead of a login page**. Fixed with `redirectGuestsTo()` in
[`bootstrap/app.php`](../../bootstrap/app.php), which resolves the market from the request path and
falls back to `Accept-Language`.

Worth recording because it was invisible until the first auth-gated route existed: Phase 3 added the
first one.
