---
name: My Lists, Shared Lists, Group Lists
area: Wishlist / Gifting
status: Phases 1, 2 and 4 built — voting open
date_added: 2026-08-15
---

# Three lists views, and why they are three

The header offers **My Lists**, **Shared Lists** and **Group Lists**. They are not three filters over
one pile — they answer three different questions, and the question each answers decides who may see
what.

| View | The question | Whose list |
|---|---|---|
| My Lists | what am I keeping? | mine, owned |
| Shared Lists | what has someone shown me? | somebody else's |
| Group Lists | what are we choosing together? | jointly acted on, for a third person |

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

Lists I own, in two labelled sections — **For me** and **For someone else**. `Lists/Index.tsx`
already does exactly this, grouping on `kind` with `lists.for_me` / `lists.for_someone_else`, so
this view is mostly already built.

The split is not decoration. A `for_someone` list is research *about* a person who must never see
it, and a `mine` list is a wish list whose whole purpose is being seen. Same table, opposite privacy
rules; showing them in one undifferentiated column invites the mistake.

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
`for_someone`.

Derivation was considered and rejected: "any list for someone that has co-givers" means a list moves
between sections when somebody is invited or removed, and a list that silently changes address is
one people lose. Choosing it up front also guarantees the two things a group list needs — a
recipient, and a reason for contributions to exist — are present from the first save.

A Group List carries two mechanisms that no other kind has.

### Voting: one vote per person per item

Any member may vote for any candidate; the tally shows on the card. It mirrors the shape the pledge
table already uses — one row per person per item, unique — so the same dual-identity pattern
(`user_id` XOR `anon_id`) applies and anonymous members can take part.

Not "pick one favourite", which forces a decision the group has not made yet, and not yes/no/maybe,
which is more state per item than a shortlist of five needs.

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

## Phases

1. ✅ **The taxonomy.** `ListKind::Group` + CHECK-constraint migration; the index served as three views
   via `ListAccess::scope()`; nav entries. Replaces the dead `?shared=1` filter. Finished 2026-08-16
   by making the kind creatable from both paths and widening the save picker, which filtered it out.
2. ✅ **Real invitations**, built 2026-08-16 — a token, an email, and redemption on sign-in. The
   *opened-link* record (remembering that I followed a `/l/{token}` link) is still open.
3. **Voting.** `list_item_votes`, the tally on the card, ordering by it.
4. ✅ **Contributions.** Gate widened past `allowsClaiming()`, read path and UI built 2026-08-16.

## Files

- `app/Enums/ListKind.php`, `app/Enums/CollaboratorRole.php`
- `app/Services/Wishlist/ListMaker.php` — the one place a list is created
- `app/Services/Wishlist/ContributionView.php` — the one place the money table lives
- `app/Http/Controllers/WishlistController.php` — `index()`, `show()`, `summarise()`
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
- `tests/Feature/GroupListTest.php`, `ListInvitationTest.php`, `tests/Unit/ContributionViewTest.php`

## See also

- [wishlists.md](wishlists.md) — why lists exist before accounts do
- [gifting-lenses.md](gifting-lenses.md) — one list, many lenses; where `kind` came from
- [navigation.md](navigation.md) — the three verbs and what hangs under Organise
