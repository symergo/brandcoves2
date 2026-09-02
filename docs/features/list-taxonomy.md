---
name: My Lists, Shared Lists, Group Lists
area: Wishlist / Gifting
status: Phases 1–4 built; claiming widened to gift lists, money moved to the pot
date_added: 2026-08-15
---

# Three lists views, and why they are three

The header offers **My Lists**, **Shared Lists** and **Group Lists**. They are not three filters over
one pile — they answer three different questions, and the question each answers decides who may see
what.

| View | The question | Whose list |
|---|---|---|
| My Lists | where is that list? | **everything I may open**, in labelled sections |
| Shared Lists | what has someone shown me? | somebody else's |
| Group Lists | what are we choosing together? | jointly acted on, for a third person |

> **My Lists became the superset on 2026-08-29.** It used to mean *lists I own, of two of the three
> kinds* — so a group list I started and a list somebody invited me to were both absent from the page
> named after finding a list, each reachable only from a nav entry you had to already know about. See
> [One page with everything on it](#one-page-with-everything-on-it) below.

## What was there before, and what was wrong with it

`/lists` scoped with `Owner::scope()` — plain ownership — so **a list shared with you was invisible
on the lists page**. `ListAccess::scope()` already unions owned *and* collaborated lists, but was
only ever used for single-list lookups (`->find($list)`), so a collaborator could open a list they
had the URL for and could not find it any other way.

A first attempt added `?kind=`, `?shared=1`, `?group=1` to the index. It shipped the wrong idea and
is being replaced rather than extended: `shared=1` filtered on `visibility != private`, which means
*"a list I own that I have shared outward"*. That is a property of my own list, not a separate
collection. Nothing in the UI ever linked to those filters, so they were dead on arrival — which is
the only reason replacing them costs nothing.

## My Lists

Every list I may open, in labelled sections — **For me**, **For someone else**, **Together for
someone**, and **Shared with me**. `Lists/Index.tsx` groups on `kind` plus one flag, `sharedWithMe`.

The sections are not decoration. A `for_someone` list is research *about* a person who must never
see it; a `mine` list is a wish list whose whole purpose is being seen; a `group` list is money and a
third person; a list shared with me belongs to somebody who can change it out from under me. Same
table, four different sets of rules — which is exactly why they can share a page as long as every
row says which it is.

## Shared Lists

Lists somebody else owns and has let me see, from **two routes, marked differently**:

- **Invited** — a `wishlist_collaborators` row, role `viewer` or `editor`.
- **Opened** — I followed a `/l/{token}` link. This needs a new record; opening a share link
  currently leaves no trace, which is why the list vanishes the moment the message is lost.

They are shown as one section with the distinction visible, because they differ in what I can do: an
invited editor can add and remove, someone who opened a link can only look and claim.

> **Invariant #4 still binds here, and this view is a new way to break it.** A shared list I am
> looking at may be a `mine` list belonging to its owner — I can see claim state. The moment that
> list is *mine*, claims must vanish. `Wishlist::shouldHideClaimsFrom(Owner)` is the single place
> that decides, and this view must not grow its own copy of the question.

### Invitations became real, 2026-08-16

`WishlistCollaboratorController::store()` looked a `User` up by email and did **nothing** when there
was no account — while returning *"If they have an account, they can see this list now."* The owner
was told something happened when nothing had, and that is the common case: the person whose help you
want is exactly the person who has not signed up.

`list_invitations` holds the promise; `Invitations` redeems it.

**Both branches now do the same thing.** Whether or not the address has an account, a row is written
and a mail is queued. The response was already identical, but only because the no-account path was a
no-op — and "identical because both are real" is a much sturdier property than "identical because one
of them does nothing". `inviting_does_not_reveal_whether_an_address_has_an_account` still passes, and
it is what stops the form being a way to test who is a member.

**Redemption is on sign-in, not on click.** A link in an email is followed by whoever holds the
inbox — the right person almost always, but a forward or a shared machine is not something a URL can
tell apart. `ClaimListInvitations` listens for `Login` and grants everything waiting for that
address, so an invitation redeems itself even when the mail is lost. The token in the URL only
decides which list to land on.

A listener rather than two edits in `MagicLinkController` and `GoogleController`: an invitation that
redeems on one sign-in path and not the other is a bug that shows up only for whichever half of
people used the other button.

Two refusals worth recording. An invitation to a list that has since been **handed over** is closed
without granting anything — handing a list over purges its collaborators deliberately, and this
would put one straight back. Inviting **yourself** is a no-op rather than an error, because it is a
slip and the site should not argue with somebody.

## Group Lists

**Chosen at creation**, not derived. A third `ListKind` — `group` — alongside `mine` and
`for_someone`. Chosen at creation is about which *section* it files under, not which *page*: it
appears under My Lists too, as one of mine.

Derivation was considered and rejected: "any list for someone that has co-givers" means a list moves
between sections when somebody is invited or removed, and a list that silently changes address is
one people lose. Choosing it up front also guarantees the two things a group list needs — a
recipient, and a reason for contributions to exist — are present from the first save.

A Group List carries two mechanisms that no other kind has.

### Voting: one vote per person per item

Built 2026-08-29. `ListKind::allowsVoting()` had existed since group lists shipped and nothing ever
asked it, so a group list was a shortlist with no way to choose from it: the money pooled fine and
the deciding happened in the group chat, which is the half of the problem the feature exists for.

Any member may vote for any candidate; the tally shows on the card and orders the list. It mirrors
the shape the pledge table already uses — one row per person per item, unique — so the same
dual-identity pattern (`user_id` XOR `anon_id`) applies and anonymous members can take part, which
is the whole point of joining an office group by link.

Not "pick one favourite", which forces a decision the group has not made yet, and not yes/no/maybe,
which is more state per item than a shortlist of five needs.

**The tally is public and the voter is not.** "Four people want the espresso machine" is what decides
something; "Bob wanted the espresso machine" is a disagreement waiting to happen inside a group
buying somebody a present, and it is needed for nothing. No payload names a voter, and
`ListItemVote::$hidden` covers the route nobody intended.

#### `insertOrIgnore`, and the reason catching the error is not good enough

The unique index decides, not an `updateOrCreate` — that is a read-then-write, and two taps on a
phone that has not finished the first request is the ordinary case rather than an exotic one. The
same reasoning as the claim being a conditional UPDATE.

The first implementation caught the unique violation and moved on, which looked equivalent and is
not: **in Postgres a failed statement aborts the surrounding transaction**, so a swallowed exception
leaves every later query in that request answering *"current transaction is aborted"*. It was found
by a test that presses the button twice — which is exactly what a person does — and only because
`RefreshDatabase` wraps each test in a transaction, making the aborted state visible where a
production request might not have shown it for months.

`insertOrIgnore` is `ON CONFLICT DO NOTHING`: it never raises, so the second press is a no-op and
the state is the one the person asked for.

### The shortlist has to read as one

The biggest misreading risk on the page. A visitor opens a group list, sees five product cards, and
concludes five presents are being bought — then acts on that. Three things fix it together, and
none of them alone:

- **A line above the grid** naming what the cards are: *"Vote for the one we should get."*
- **Ordering by tally**, most-backed first. A shortlist that does not visibly rank is a list.

  **On load, not live.** The server sorts by the tally, so arriving at the link shows where the group
  has landed — and then the page holds that order for as long as you are on it. Voting re-renders in
  place, so the card you just backed used to climb past the ones above it, under your finger, on a
  two-column grid, while you were still reading them. Approval voting invites exactly that: back
  three things and the page rearranged itself three times. The counts still update live, which is the
  feedback that matters; only the positions are frozen. `Lists/Shared` re-captures the order when the
  *set* of items changes — an addition or a removal has to find a place — and a vote changes counts
  rather than membership, so it never does.

  Since 2026-09-01 this is also a setting: `wishlists.voting_enabled`, off leaves the shortlist in
  its own order with no tally at all. See below.
- **The vote is the card's only action.** Money moved to the header (see above), so a candidate
  carries exactly one thing to do.

### Contributions: only the organiser sees amounts

Everyone sees the running total and their own share. The organiser — the list owner — sees the
breakdown, because they are the person who fronts the money and collects afterwards.

This **inverts the existing pledge privacy rule**, and the inversion is the whole design point:

| | `mine` list | `group` list |
|---|---|---|
| Who is the owner | the recipient | the organiser |
| Owner may see contributions | **never** (invariant #4) | **yes** |
| Why | the surprise is theirs | the recipient is not on this list at all |

On a `mine` list the owner *is* the person being surprised, so `shouldHideClaimsFrom()` hides
everything. On a group list the recipient is a third party who never sees the list, so there is no
surprise to protect from the owner — and a visible ladder of who put in what is exactly what the
organiser needs. Members do not see each other's amounts, because a public ladder is social pressure
on whoever put in least.

### Built 2026-08-16, and what the shape turned out to be

`GiftPledgeController::resolve()` gated on `allowsClaiming()`, which is `mine`-only — so a list for a
third person **structurally could not carry contributions**, and it asked `shouldHideClaimsFrom()`
separately, which locked out the organiser. Both are now one question, asked once:

```php
// app/Models/Wishlist.php
public function allowsContributionsFrom(Owner $viewer): bool
{
    return $viewer->exists()
        && $this->kind->allowsContributions()
        && ($this->kind->ownerSeesContributions() || ! $this->shouldHideClaimsFrom($viewer));
}
```

`$viewer->exists()` is in there for a reason found by a test rather than by reading: the page renders
its "I am in" button from this value, and without the check a visitor whose cookie identity had not
been minted yet was shown a control that 403'd when pressed. The endpoint had always checked it
separately, which is exactly how a mirror drifts.

**The read path is `app/Services/Wishlist/ContributionView.php`, and it is the only one.** Its whole
job is this table:

| viewer | `mine` list | `group` list |
|---|---|---|
| the owner | **no key at all** | `{ total, count, mine, breakdown }` |
| anyone else | `{ total, count, mine }` | `{ total, count, mine }` |

Three things about it are load-bearing:

- **Absent, not zero.** An item with nothing to say is missing from the returned array entirely, so
  the controllers spread `...isset($c[$id]) ? [...] : []` and the owner of a wish list receives no
  key. A `contributions: null` on every item is a channel that goes live the day somebody tidies the
  null away — and they would tidy it away without knowing what it was for. Same discipline as
  `progress` in `SharedListController`.
- **`$isOwner` **and** the kind, never the kind alone.** `WishlistController::show()` loads through
  `ListAccess::scope()`, so a *collaborator* on a group list opens the organiser's page. They are a
  member of the pool rather than the person collecting it, and asking only "is this a group list?"
  hands them the ladder. `a_collaborator_on_a_group_list_does_not_get_the_breakdown` pins it.
- **`$isOwner` must not be reused as "hide everything".** In `SharedListController` that variable is
  `shouldHideClaimsFrom()`, which is **true for a group organiser too** — passing it through as a
  suppression flag would hide the breakdown from the one person the feature is for. It is passed to
  `ContributionView` as an input to the table, not applied before it.

`WishlistController::asked()` — somebody else's `mine` items rendered inside my page — deliberately
carries **no** contributions. A total there would be legal, since I am a giver rather than the
recipient, but it is the one place where copying the group-list branch would hand a breakdown about
one person's list to a different person entirely. The docblock there says so.

## Filling one list, rather than saving one product

Added 2026-08-29. A fourth way a list gets filled, and the first one that treats filling a list as a
single act rather than as a run of unrelated saves.

**What it replaced.** "Find things to add" on `Lists/Show` linked to a bare `/{market}/search`, which
knew nothing about the list it had been reached from. Every product then cost: open the picker, wait
for `/list-options`, scan three sections, find the same list you chose thirty seconds ago, tap it.
Ten items, ten identical decisions. The destination is the *least* variable part of filling a list
and it was the part being asked about most.

`GET /lists/{list}/add` turns the mode on and lands on search; `GET /done-adding` turns it off and
returns to the list. While it is on, a bookmark adds straight to that list.

### Three decisions

**The session, not a query parameter.** `?to={list}` was the obvious alternative and is worse: it
would have to survive search pagination, every facet link, a sort change, a click into a product and
back, a guide, a brand page — every internal link on every discovery surface. Any one that forgot to
carry it drops the mode silently, and the visitor finds out afterwards by looking at their list.

**Which is only safe because the mode is always visible.** `AddingToBar` sits under the header on
every page for as long as the mode is on, naming the list and offering the way out in the same
sentence. A session flag that quietly redirected saves would be a trap; a mode you can see at all
times cannot surprise you. The bar is the safeguard here — not an expiry, which would end the mode
mid-run for exactly the person using it properly.

**The id is stored; the title is resolved per request** (`savingTo`, a closure in
`HandleInertiaRequests` beside `unreadCount`, so it costs nothing when the mode is off). That buys
one primary-key lookup and makes the mode self-healing: rename the list and the bar follows, delete
it or lose access and the mode ends rather than pointing at something that is not there. `canEdit` is
re-checked on every request rather than trusted from the session — a collaborator can be demoted, a
list can be handed over, and a mode that outlived permission would send every subsequent save into a
403 with nothing on screen explaining why.

### The bookmark changes what it means

Ordinarily it answers *"have I kept this anywhere?"* — any list of yours counts, because a thing on
your research list for your mother is still a thing you have already found.

During a run that is the wrong question, and answering it would tick items that are on a different
list while hiding the ones just added. `GET /saved-items?list=` returns `listGroupIds` alongside
`groupIds` in the same round trip, and `savedItems.ts` holds both. The per-list read goes through
`ListAccess`, because asking "what is on this list" about a list you have no part in is a read of
somebody's list membership and is gated like one.

The mode is a **default, not a lock**: the picker still reaches every list, and a save that names one
goes there — which is why `markSaved()` takes an `onActiveList` flag. Saving to Books during a
Camping run must not tick the bookmark, because the item is still not on Camping.

## What already exists and is being reused

Worth stating, because most of this was built and then never wired to anything:

- `GiftPledge` model, `GiftPledgeController`, both `/l/{token}/pledge/{item}` routes, and the entire
  `pledges` copy block — complete, with **zero** React referencing them.
- `ListAccess::scope()` / `canEdit()` / `isOwner()` — the union query this view needs.
- `Lists/Index.tsx`'s two-section grouping by `kind`.
- `ListTools.tsx`'s panel pattern and the People roster with its `viewer` / `editor` select.
- `Owner::attributes()` / `scope()` taking column names, which is what lets pledges and votes use
  `user_id`/`anon_id` while wishlists use `owner_user_id`/`owner_anon_id`.

## How a group list is created, and why `together` is a boolean

Both creation paths — the form on My Lists and the save picker's "new list" — went through twenty
duplicated lines that resolved the recipient, minted a new person and then decided the kind. They
now share `app/Services/Wishlist/ListMaker.php`. That duplication was the reason to expect a
divergence, and the consequence of one is a list whose kind disagrees with its recipient, which is
the ambiguity `ListKind` exists to remove.

The request carries `together` as a **boolean**, never `kind` as a string. A client-supplied kind
would have to be re-derived and re-checked against the recipient anyway; a boolean cannot contradict
anything. The recipient still decides mine-vs-else and `together` only chooses between the two ways
of buying for somebody — so "for me, together" resolves to `Mine` rather than to a group list with
nobody in it.

A group list therefore always has a recipient, guaranteed twice: in the service, and by
`wishlists_group_has_recipient` (`2026_08_16_000100`). Twice because the service is one of two
callers today and nothing promises it stays two.

**`Wishlist::isForSomeoneElse()` was wrong the moment `Group` became reachable.** It compared against
`ForSomeone` alone while `ListKind::isForSomeoneElse()` included `Group`, and
`SharedListController` uses the model's version to decide whose name a visitor is shown — so a group
list would have displayed the **organiser's** name where the recipient's belongs, telling the people
buying the present that the list belongs to the person it is a surprise for. It delegates to the enum
now. The bug was invisible for as long as the kind was uncreatable, which is the hazard of shipping a
value nothing can write.

## One page with everything on it

Added 2026-08-29, together with three smaller changes to the same surface. All four come from one
complaint: the gift tools were built one at a time, and each one filed itself somewhere, so finding
the thing you made was a matter of remembering which page had adopted it.

### My Lists is now the superset

`WishlistController::index()` runs **two queries** for the `mine` view — `Owner::scope()` for rows I
own, and `ListAccess::scope()->whereNot(owned)` for rows somebody let me into — and concatenates
them. Two queries rather than one widened scope, and the reason is a privacy rule rather than a
preference:

> **`withCount('suggestions')` may only be attached to rows I own.** A pending suggestion is a
> message addressed to the list's owner, and a collaborator learning that one arrived is a leak of
> the fact that somebody is thinking about this person. `summarise()` reads `suggestions_count ??
> null`, so *leaving the `withCount` off* is what makes the key null on somebody else's list rather
> than merely zero — the same absent-not-zero discipline `ContributionView` uses.

`rows()` takes `bool $owned` and is the only place that decides. It also loads `owner` and **my own**
collaborator row — never the whole roster, which would hand every card the names of everybody else
invited — so the card can say *From Sanne · may add and remove*.

`?view=shared` and `?view=group` still answer the narrow questions and the nav still links to them.
What changed is that they are no longer the *only* way to reach those rows.

`a_group_list_does_not_appear_under_my_lists` asserted the opposite until this change, on the stated
grounds that showing a group list in two views makes the sections decoration. The sections survived;
the exclusion did not, and the test was rewritten rather than deleted so the reversal is on the
record.

### Two words that both mean "shared"

The card carries both, and they point in opposite directions:

| On the card | Means | Rendered as |
|---|---|---|
| `visibility != private` | *I have published this outward* | Shared / Private pill |
| `sharedWithMe` | *this is not mine at all* | "From :name" badge + my role |

The pill is suppressed on a row I do not own: it reports the owner's publishing decision, and
showing it beside their name reads as if it were mine to change.

### More than one wish list for yourself

There is still exactly **one default** — the list a one-tap save lands in, so "where did my save go?"
keeps its single answer, enforced by the partial unique index `DefaultList` documents. But the Gift
Cove rendered that one list as though it were the only one a person may keep for themselves, and it
never was: the save picker and the create form have always made more. Showing one and hiding the
rest reads as a limit rather than as an omission.

`GiftCoveController` now sends `wishlists` (plural, default first, then most recently touched) in
place of `mine`, each carrying its occasion. The cards that act on "your wishlist" — wishlist,
occasion, quiz — use the first, because that is the one somebody means when they have not said
which. `?new=mine` starts another, the same way the other cards open the create form on their own
shape.

Occasions are why the plural matters at all: a registry is an ordinary wish list with a date on it,
so "my wedding" and "things I want some day" are two lists for me, not one list I have to choose
between.

### Registry became "Special occasion"

`registry.badge` was *Registry* / *Geschenkenlijst* — a word for the artefact. The control turns your
own wish list into one by attaching an occasion and a date, which is what "Special occasion" says.
`registry.heading` and `registry.none` moved with it; the stored `event_type` / `event_date` columns
and everything downstream are untouched, because this is a rename of the control and not of the
feature.

The manual quotes the label on the screen, so `gift_cove.registry_step1` moved in all four languages
at the same time. `the_occasion_panel_is_called_what_the_manual_calls_it` now asserts that the step
contains the badge, in every language — a mechanical version of the human who used to notice.

### Share moved into the row of things you can do

It was a primary button in the header, beside Delete — a row about *administering* a list: renaming
it, giving it away, getting rid of it. Sharing is not that, and the tab that displayed the link only
existed once sharing was already on, so the one control people came for sat in a different place
before and after the single press that mattered.

`ListTools` now shows Share first, always, for the owner. Pressing it when sharing is off patches
`visibility` to `link` and opens the panel `onSuccess` — so a failed patch leaves an unshared list
rather than a panel with no link in it. The header keeps Delete alone. Two copies of one control on
one screen is not twice as findable, which is why this is a move and not an addition.

A collaborator on an already-shared list still sees the tab: they cannot change visibility, but they
can pass the link on.

## Claiming on a gift list, and the same inversion a second time

Built 2026-08-29. `ListKind::allowsClaiming()` was `mine`-only, so "help me choose or don't double
up" — this document's own words for what a `for_someone` list is *for* — had no mechanism behind it.
Two siblings buying for a parent could share a list and had no way to say which of them was getting
what.

The rule that produced it is still right and still enforced: **never gate claiming on visibility**.
That bug made every shared list claimable, including private research about somebody's mother. The
conclusion drawn from it was simply too strong. `Group` stays unclaimable, which is what keeps this
from collapsing back into "any shared list" — one present, nothing to divide.

### The privacy rule inverts, exactly as it did for money

| | `mine` list | `for_someone` list |
|---|---|---|
| Who the owner is | the recipient | a co-giver |
| Owner may see claims | **never** (invariant #4) | **yes**, by default |
| Why | the surprise is theirs | the recipient is not on this list at all |

The same table [contributions](#contributions-only-the-organiser-sees-amounts) already draws for a
group list, one mechanism over. Two mechanisms, one rule: **the owner is hidden from precisely when
the owner is the person being surprised.**

### Two settings, because they were always two questions

The first shape of this was a single three-valued `ClaimVisibility`: `anonymous`, `named`,
`hidden_from_owner`. It lasted about an hour, and the thing that broke it is worth writing down —
**`hidden_from_owner` was a value of the wrong column.**

Whether *I* see claims off my own list, and whether the people buying see *each other's* names, are
independent. Conflating them meant the third option changed meaning depending on the kind of list,
and made one perfectly ordinary combination impossible to express: *show me the claims, and let the
others see each other's names.*

| Column | Question | Default |
|---|---|---|
| `wishlists.owner_sees_claims` | may **I** see what has been claimed? | the kind decides |
| `wishlists.claim_visibility` | do the buyers see **each other's** names? | `anonymous` |

**Invariant #4 became a default rather than an absolute**, and CLAUDE.md was reworded to say so. A
wish list still hides by default, because the surprise is the whole point — and *nothing infers
otherwise*: not sharing, not inviting somebody, not putting an occasion on it. Only an explicit
press turns it on. The column is **nullable**, and that is load-bearing: null is "never asked", so
`Wishlist::ownerSeesClaims()` falls back to the kind. Storing the default instead would make every
list assert a preference nobody expressed, and a later change to what a kind implies would silently
skip all of them.

The opt-out survives on the other side: a gift-list owner who might end up receiving from the list
turns it off, and everybody else keeps coordinating.

**A name is stored only while the list says `named`, and never backfilled.** Switching a list to
`named` afterwards leaves existing claims reading "spoken for", which is honest: nobody consented to
being named then. And the claimer is told what pressing the button discloses *before* they press it,
on the shared page — consent given inside a settings panel somebody else opened is not consent.

### `$isOwner` had to be split, and this is the hazard the doc already named

`SharedListController` set `$isOwner = $list->shouldHideClaimsFrom($owner)` and reused that one
variable for **eight** questions. The two agreed while every hidden owner was also every owner, and
stopped agreeing the moment a gift list's owner could see claims — so all eight would have changed
meaning silently.

```php
$isOwner    = $list->isOwnedBy($owner);            // identity
$hideClaims = $list->shouldHideClaimsFrom($owner); // policy
```

This document had already written the warning down for `ContributionView` — *"`$isOwner` must not be
reused as 'hide everything'"* — and the mirror drifted anyway, because the variable was named after
one of its two meanings. `SuggestionController` had the same substitution and the same fix.

`app/Services/Wishlist/ClaimView.php` now holds the whole table, beside `ContributionView` and with
the same **absent, not null** discipline: an owner who may know nothing receives no `claimed` key at
all. The page reads `claimed === undefined` as "no claiming here", which is why it must stay a
spread rather than becoming three nullable fields again.

### What a list offers is derived; what a list *is* is not

`Wishlist::hasCoGivers()` — shared by link, or has a collaborator. `allowsClaiming()` is the kind
**and** that.

Most lists are private, of every kind: a gift list about somebody is usually solo research, and the
default wish list every account gets is simply where a bookmark lands. Claim buttons and a
claim-privacy setting on a solo list describe readers who do not exist.

**This does not contradict the refusal to derive `ListKind`** above, and the difference is worth
stating because the two read alike. Deriving the kind was rejected because "a list that silently
changes address is one people lose". Nothing moves here: same kind, same section, same URL. Only
controls appear, on the same list, at the moment they begin to mean something. Two surfaces were
already doing exactly this by hand — the quiz tab (`shared && claimable`) and the owner-view note —
and this makes it one question asked once.

## The money moved off the item, 2026-08-29

`gift_pledges.item_id` was `NOT NULL`, so a pledge always named a product. Right for the case the
table was written for — its own comment says which: *"one person claims it, the others pledge
against it"* — and that is a `mine` list, where several people chipping in for the one expensive
thing on Anna's list is a fact **about that thing**.

On a group list it is incoherent. The list is a shortlist *because* the group has not decided, so
pledging against a candidate asks people to bet, and most of those pledges end up attached to
something nobody buys. The money belongs to the pot; the votes decide what the pot buys.

### And then off the wish list too, 2026-08-30

The first cut kept `mine` pooling per item, on the argument above: chipping in for the one expensive
thing on Anna's list is a fact about that thing. Rendered, it put an **"I'm in" under every card** of
a six-item wish list — the secondary action on each, six times, beside the claim button that is the
actual one.

The distinction that survives is cleaner than the one it replaced:

> On a wish list you **claim** a thing — "I am buying this one". On a group list you **contribute**
> to a thing the group buys together.

Going in with somebody on a wishlist item *is* a group gift, and a group list is what that is. So
`allowsContributions()` is `Group` only, `ContributionView::forItems()` is gone, and
`2026_08_30_000100` drops `item_id` rather than leaving a nullable column nothing writes — which
reads as a capability to whoever meets it next, and is the drift this codebase keeps finding.

Safe to drop outright because `gift_pledges` was empty in every environment: the write path only
reached a UI on 2026-08-16 and nothing had used it. With rows it would have had to fold each
per-item pledge into its list's pot and reconcile one person having pledged against two items of the
same list. Worth writing down rather than left as an assumption somebody re-derives.

### One index said both rules, for a day

While both shapes existed the uniqueness was `(wishlist_id, item_id, user_id) NULLS NOT DISTINCT`.
Postgres treats nulls as distinct in a unique index by default, so without that clause one person
could pledge to the same pot any number of times — every pot row has a null `item_id`, and no two
nulls collide. With it, one index said both things at once: unique per person per **list** when
`item_id` was null, per person per **item** when it was set.

Worth recording because it was the right shape for the model it served, and because the model lasted
a day. It is a plain partial unique per person per list now: the column whose null carried the
meaning is gone.

### A question that stopped having two answers

`poolsOnTheList()` existed briefly beside `ownerSeesContributions()`, to keep *where the money
attaches* apart from *who may look at it* — two questions with the same answer, which is the shape
that fails silently the day they diverge. That is exactly how the quiz ended up offerable over a list
about somebody else, on `shared && claimable` standing in for "mine".

With per-item pledging gone there is only one place money can attach, so the question no longer has
two answers and the method went with it. Keeping a distinction alive after the thing it
distinguished has been removed is its own kind of drift.

## Adding to somebody else's list, 2026-08-29

A link-holder's only route onto a list was `SuggestionController`, which lands **pending** for the
owner to accept. Right for a `mine` list, where an addition is a message to somebody about their own
wish list. Wrong for the other two: there the owner is a co-giver or an organiser, everybody is
researching a third person, and making each addition wait turns a shared workspace into an inbox
that the person who asked for help has to empty.

`ListKind::acceptsDirectAdditions()` — true for everything except `Mine`.

The gate that used to sit here was `abort_unless($list->allowsClaiming())`, justified as "suggesting
into private research would tell a stranger it exists". **The premise does not hold**: `findShared()`
refuses a private list, so nobody arrives by accident — everybody on that page was sent the link on
purpose, and helping fill the list is why. The rule was protecting research from the people its owner
had invited to help with it.

**A hand-written item still waits, on every kind**, and that split is the one judgement call. A
catalogue product is a `group_id` — structured, ours, nothing to moderate. A typed title and price is
free text arriving through a link that can be forwarded anywhere, which is the moderation surface
[wishlists.md](wishlists.md) deliberately declined to open; the pending queue is the control that
already exists for it.

## A group list nobody could join, 2026-08-29

`ListTools`'s **People** tab was `access.isOwner && list.kind === 'for_someone'`. A group list is the
one kind that is *useless* on its own — voting needs voters and a pot needs people — and its
organiser had no way to invite anybody. `WishlistCollaboratorController` has always accepted any
kind, and `Invitations` too, so the gate was in the tab array and nowhere else: the whole mechanism
was built, tested, and reachable only by somebody who already had a `for_someone` list.

The tab is now `access.isOwner && list.kind !== 'mine'`. Phrased as "not a wish list" rather than
"one of these two", because the thing that makes the tab wrong is having no third party to
coordinate about, and a fourth kind — if one ever arrives — would be wrong for that reason or not
at all.

`GiftCoveController`'s `counts` had the matching hole: `giftLists` counts `ForSomeone` alone, so the
hub that exists to show what you already have could not show that a group gift existed. It carries
`groupLists` now.

The two are one bug seen twice, which is the tell. The kind became creatable on 2026-08-16 and every
surface that had been written before that date kept asking the question it was written to ask.

## Sharing is a link, 2026-08-30

Co-givers were added one email address at a time, each with a viewer/editor
role — sitting directly under the share link that already granted the same
thing to whoever it reached. Two ways to let somebody in, one of which needed
their address and had to be done again for each of them.

Now there is the link, and one setting saying what it carries.

### What this forced: the opened-link record

`ListAccess::scope()` unioned owned lists with **invited** ones, so Shared Lists
was built entirely on collaborators. Take invitations away and a whole nav entry
is permanently empty.

That was already the bug for anybody sent a link rather than an invitation —
which is most people — and it is Phase 2's open half, finally closed.
`list_opens` records that somebody followed a `/l/{token}` link, and the union
includes it.

**A bookmark, not a grant.** Access is still the token plus
`visibility != private`, decided in `SharedListController` as before. Turning
sharing off takes the list away from everyone who ever opened it, which is what
turning sharing off has to mean, and it is why adding this union is safe.

Not `wishlist_collaborators`: its `user_id` is `NOT NULL` and these readers are
frequently signed out. The semantics differ too, which is the better reason — a
collaborator was *granted* something by name, this records that somebody
*arrived*, and one table holding both makes "who did the owner invite"
unanswerable.

> **`ON CONFLICT` cannot infer a partial unique index.** The first cut used the
> two partials `gift_pledges` uses, which looked right and 500'd every shared
> list, because Eloquent's `upsert()` does not repeat the index's `WHERE`. One
> `NULLS NOT DISTINCT` index over all three columns does it instead — exactly
> one identity column is ever set, so the triple is unique per person per list
> either way.

### `link_can_add`, which the kind used to decide

Whether somebody holding the link may put things on the list was derived from
its kind: `for_someone` and `group` took additions straight on, `mine` queued
them. Good defaults, wrong shape for the question — it turns on how well you
know the people holding the link, and the kind cannot tell a family gift list
from a wish list sent to forty colleagues.

It is a setting now, nullable so the kind still answers until somebody says
otherwise, exactly as `owner_sees_claims` is. **Off routes to the approval queue
rather than refusing**: what somebody adds goes where the owner can accept or
dismiss it, and nobody is told a setting rejected them. A hand-written item waits
regardless — free text through a forwardable link, and the queue is what
moderates it.

### What stayed, and why

Nothing invites any more, but **invitations already sent still redeem**. They are
sitting in real inboxes and a link in an email is followed whenever somebody gets
round to it; deleting `Invitations::claimFor()` would turn every one of them into
a dead end long after anybody could work out why. `invite()` is gone with the
form that called it.

The **roster survives as an undo**. Real people were granted real access by name
before this, and `ListAccess` still honours `wishlist_collaborators` — dropping
the union would revoke them silently. The owner keeps a way to take it back;
there is just no longer a way to add.

## Phases

1. ✅ **The taxonomy.** `ListKind::Group` + CHECK-constraint migration; the index served as three views
   via `ListAccess::scope()`; nav entries. Replaces the dead `?shared=1` filter. Finished 2026-08-16
   by making the kind creatable from both paths and widening the save picker, which filtered it out.
2. ✅ **Real invitations**, built 2026-08-16 — a token, an email, and redemption on sign-in. The
   *opened-link* record (remembering that I followed a `/l/{token}` link) is still open.
3. ✅ **Voting.** `list_item_votes`, the tally on the card, ordering by it. Finished 2026-08-29,
   together with moving the money off the candidates so a card has one action rather than two.
4. ✅ **Contributions.** Gate widened past `allowsClaiming()`, read path and UI built 2026-08-16.

## Files

- `app/Enums/ListKind.php`, `app/Enums/CollaboratorRole.php`
- `app/Services/Wishlist/AddingMode.php` — the list currently being filled
- `resources/js/Components/AddingToBar.tsx`, `resources/js/addingMode.ts` — the visible half
- `resources/js/savedItems.ts` — two sets, because the bookmark answers two questions
- `tests/Feature/AddingModeTest.php`
- `app/Services/Wishlist/ListMaker.php` — the one place a list is created
- `app/Services/Wishlist/DefaultList.php`, `DefaultTitle.php` — the one list every owner has,
  and its name looked up in the reader's language rather than the one it was stored in
  (see [localisation.md](localisation.md#a-stored-string-we-wrote-my-wishlist))
- `app/Services/Wishlist/ContributionView.php` — the one place the money table lives
- `app/Http/Controllers/WishlistController.php` — `index()`, `rows()`, `show()`, `summarise()`
- `app/Http/Controllers/GiftCoveController.php` — `wishlists`, plural
- `resources/js/Pages/GiftCove.tsx`, `resources/js/Components/ListTools.tsx`
- `app/Http/Controllers/SharedListController.php` — `show()`
- `app/Http/Controllers/GiftPledgeController.php`
- `app/Support/ListAccess.php`, `app/Support/Owner.php`
- `app/Models/Wishlist.php`, `app/Models/GiftPledge.php`, `app/Models/WishlistCollaborator.php`
- `database/migrations/2026_08_16_000100_a_group_list_needs_a_recipient.php`
- `resources/js/Components/Pledge.tsx` — one component, both pages
- `resources/js/Pages/Lists/Index.tsx`, `Show.tsx`, `Shared.tsx`,
  `resources/js/Components/SaveToList.tsx`, `ListTools.tsx`
- `lang/*/site.php` — `lists`, `pledges`, `nav`
- `app/Services/Wishlist/Invitations.php`, `app/Models/ListInvitation.php`,
  `app/Listeners/ClaimListInvitations.php`, `app/Mail/ListInvitationMail.php`
- `database/migrations/2026_08_16_000400_create_list_invitations.php`
- `tests/Feature/GroupListTest.php`, `ListInvitationTest.php`, `CopyMatchesCodeTest.php`,
  `tests/Unit/ContributionViewTest.php`

## See also

- [wishlists.md](wishlists.md) — why lists exist before accounts do
- [gifting-lenses.md](gifting-lenses.md) — one list, many lenses; where `kind` came from
- [navigation.md](navigation.md) — the three verbs and what hangs under Organise
