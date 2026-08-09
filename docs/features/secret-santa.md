---
name: Secret Santa
area: Gifting / Social
status: Active
date_added: 2026-08-09
---

# Secret Santa

**An assignment layer, not a subsystem.** The draw decides who your
[`GiftTarget`](gifting-lenses.md) is; from there it is the ordinary gift page —
their list on one side, what you found on the other.

What is reused, unchanged: the giftee's list is a wishlist; the reveal is a gated
view of an existing share link; claiming already stops two people in a family
group buying the same thing; the claim-privacy rule already holds; "I've bought
it" is `wishlist_items.marked_sent_at`.

Genuinely new: `secret_santa_groups`, `secret_santa_members`, and the draw.

A group is deliberately **not** a `wishlist` row. It holds no items, and
overloading the table would let `SharedListController::findShared()` serve a
group as a list.

## The draw is a matching problem, not a shuffle

v1 shuffled up to 200 times and gave up. That has two failure modes that only
appear once a group is awkward enough to matter:

1. **It fails on solvable inputs.** Ten members who may each give to exactly one
   other person admit precisely one arrangement, and there are 1,334,961
   derangements of ten items — so 200 random attempts succeed about one time in
   six thousand, on an input with a perfectly good answer. The organiser
   experiences that as "the button doesn't work".
2. **It cannot say why.** "Could not draw after 200 tries" is indistinguishable
   from a genuinely impossible constraint set, so nobody knows whether to retry
   or to go and talk to their brother about his exclusion list.

`SecretSantaDraw` models it as a **bipartite perfect matching** — givers on one
side, receivers on the other, an edge wherever a pairing is allowed — and finds
one by augmenting-path search. It succeeds whenever a solution exists, and when
it fails it names the member who cannot be matched, which is the actionable part.

Randomness lives in shuffling each giver's candidate list, not in retrying, so
the draw differs every year without its success depending on luck. Givers with
the fewest options are processed first: not needed for correctness, but it cuts
the reshuffling and makes the named blocker usually the real one.

Pure and unit-tested. `it_solves_a_set_a_retry_loop_would_give_up_on` is the
assertion the class exists for — a shuffle-and-retry implementation passes every
other test in that file.

## The pairing is encrypted, and no `Recipient` row is minted

v1 stored `assigned_to` in plain text and its own planning notes flagged that as
a defect: a backup, a support session or a laptop copy in a synced folder hands
somebody the whole game. It is now an `encrypted` cast, and `$hidden` as well —
encryption stops a database dump, hiding stops an accidental `->toArray()`, and
either alone is one edit from failing.

**The corollary matters as much as the rule.** The obvious way to reuse the gift
page would be to mint a `Recipient` row per giver at draw time. That would write
the giftee's name into `recipients.name` in plain text one table over and make
the encryption decorative. The giftee is resolved at render time through
`GiftTarget` instead — one place where the pairing lives.

`bc:scrub` anonymises members and nulls the assignment.

## Participation without an account

`secret_santa_members.user_id` is nullable and the join token is the credential.
Requiring a login to be in an office Secret Santa is how most of the office does
not join, and the organiser runs it in a spreadsheet instead.

`wishlist_collaborators` is **not** reused for membership: its `user_id` is
`NOT NULL`, and the semantics differ anyway.

## The organiser is a player, and not a spectator

They are added as a member on create, because running one without being in it is
rare and joining separately is a step everyone forgets.

They see who is in, who has a list, and how many have finished shopping — never
who drew whom. v1 let the organiser read the pairings outright, which quietly
makes one player a spectator of everyone else's game. Same reasoning as the
reminder in [the occasion lens](gifting-lenses.md): a nudge names nobody who has
not yet bought.

## The loop worth having

Joining a group is the strongest possible reason to build your own wishlist —
whoever drew you has nothing to go on until you do. The member page pushes
list-building before the draw. Without it Secret Santa degrades to "here is a
name, good luck", which is the version that already exists in every group chat.

## Email

One per member, each naming exactly one pairing — the single channel through
which the game can be spoiled, which is why everything else is aggregate.

It carries no product data at all: the giftee's list is a link. That keeps the
Amazon rules out of the picture entirely (`Source::allowsEmail()`), and **a mail
with nothing to filter cannot be got wrong by a later edit** — the same reasoning
as [cove-subscriptions.md](cove-subscriptions.md).

Queued only after the draw transaction commits. Mail queued inside it would go
out even if the write rolled back, and a sent email cannot be unsent.

## Deleting a group

Only the organiser. A member who wants out is a different act with a different
consequence — the draw has to be repaired around them — and giving one person a
button that ends everyone else's exchange is not that.

Deletion takes the group and its members, and stops there:

- **Wishlists survive.** A member attached a list they already owned; the group
  borrowed it and does not get to take it. The FK is `nullOnDelete` on the
  member side for exactly this reason.
- **Claims survive.** Somebody said they would buy that, and may already have.
  Releasing the item would send a second person to the shops.

Nobody is emailed. There is no un-delete and no notification, so the confirm
prompt says which group it is by name, and says something stronger once the draw
has happened — at that point people are already shopping.

## Money

`budget_min` / `budget_max` are **integer cents** (invariant #7). v1 used
`DECIMAL(10,2)` here and integer cents everywhere else, which is exactly how a
rounding difference gets into a budget comparison.

## Files

- `app/Services/Gift/SecretSantaDraw.php`, `DrawImpossible.php`
- `app/Models/SecretSantaGroup.php`, `SecretSantaMember.php`, `app/Enums/SantaStatus.php`
- `app/Http/Controllers/SecretSantaController.php`
- `app/Mail/SecretSantaAssignmentMail.php`, `resources/views/mail/santa-assignment.blade.php`
- `resources/js/Pages/Santa/Group.tsx`, `Me.tsx`
- `tests/Unit/SecretSantaDrawTest.php`, `tests/Feature/SecretSantaTest.php`

## Not built

Year-on-year group reuse with a "don't repeat last year's pairing" exclusion, and
collapsing the chain when somebody drops out. Both were specced in v1 and neither
was built there either.
