---
name: My Lists, Shared Lists, Group Lists
area: Wishlist / Gifting
status: Designed — phase 1 in progress
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

### Invitations become real

Today `WishlistCollaboratorController::store()` looks up a `User` by email and silently does nothing
when there is no account — while returning `lists.collaborator_invited`, *"If they have an account,
they can see this list now."* The owner is told something happened when nothing did.

An invitation record with a token, delivered by email, replaces that. Deliberately **not** an
oracle, exactly as the current controller is careful to be: the response must not differ between an
address that has an account and one that does not, or the form becomes a way to test whether
somebody is a member.

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

`GiftPledgeController::resolve()` currently gates on `allowsClaiming()`, which is `mine`-only — so a
list for a third person **structurally cannot carry contributions today**. That gate has to become a
question about contributions rather than about claiming.

## What already exists and is being reused

Worth stating, because most of this was built and then never wired to anything:

- `GiftPledge` model, `GiftPledgeController`, both `/l/{token}/pledge/{item}` routes, and the entire
  `pledges` copy block — complete, with **zero** React referencing them.
- `ListAccess::scope()` / `canEdit()` / `isOwner()` — the union query this view needs.
- `Lists/Index.tsx`'s two-section grouping by `kind`.
- `ListTools.tsx`'s panel pattern and the People roster with its `viewer` / `editor` select.
- `Owner::attributes()` / `scope()` taking column names, which is what lets pledges and votes use
  `user_id`/`anon_id` while wishlists use `owner_user_id`/`owner_anon_id`.

## Phases

1. **The taxonomy.** `ListKind::Group` + CHECK-constraint migration; the index served as three views
   via `ListAccess::scope()`; nav entries. Replaces the dead `?shared=1` filter.
2. **Shared Lists in full.** The opened-link record, and real invitations with a token and an email.
3. **Voting.** `list_item_votes`, the tally on the card, ordering by it.
4. **Contributions.** Extend the pledge gate past `allowsClaiming()`, and build the read path and UI
   that has never existed.

## Files

- `app/Enums/ListKind.php`, `app/Enums/CollaboratorRole.php`
- `app/Http/Controllers/WishlistController.php` — `index()`, `summarise()`
- `app/Support/ListAccess.php`, `app/Support/Owner.php`
- `app/Models/Wishlist.php`, `app/Models/GiftPledge.php`, `app/Models/WishlistCollaborator.php`
- `resources/js/Pages/Lists/Index.tsx`, `resources/js/Components/ListTools.tsx`
- `lang/*/site.php` — `lists`, `pledges`, `nav`

## See also

- [wishlists.md](wishlists.md) — why lists exist before accounts do
- [gifting-lenses.md](gifting-lenses.md) — one list, many lenses; where `kind` came from
- [navigation.md](navigation.md) — the three verbs and what hangs under Organise
