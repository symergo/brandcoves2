---
name: Copying items between lists
area: Wishlist / Gifting
status: Active
date_added: 2026-09-02
---

# "Put this on another list too"

Two things wanted the same operation from opposite directions:

- Somebody saved a scarf while shopping for one person and is now shopping for
  two.
- The recipient's own list is readable in the **Ask** panel, and every row on it
  is something the giver wants on the list they are actually working from —
  which, until now, they could read and not act on.

One service ([`ItemMover`](../../app/Services/Wishlist/ItemMover.php)), two
endpoints, because the two have different sources and therefore different gates.

## Copy, never move

The only verb, and the decision that shapes everything else.

Removal already exists on every row, so a move would be a second way to do what
the page can already do in two deliberate presses — with a failure mode the copy
does not have. Pressed on the wrong row a copy costs one row somebody deletes; a
move destroys the original. And from the Ask panel a move would delete a row from
somebody *else's* wishlist because a third party pressed a button.

## A claim never travels

`claimed_by_hash`, `claimed_by_name`, `claimed_at`, `marked_sent_at`,
`suggested_by_user_id` are all dropped. This is the whole of the care this
feature needs.

A claim is a fact about **one** list — who agreed to buy this *there* — and
carrying it to another would announce, on a page its owner can see, that
something has been bought. On a wish list that is invariant 4 broken by a copy
button. `suggested_by_user_id` goes for the same reason in miniature: it names a
person in a context they did not agree to.

Two tests hold this: `a_claim_never_travels_with_a_copy` and
`a_copy_from_a_shared_list_arrives_unclaimed`.

## What does come along

The note, the price snapshot, the source and the external id. The note in
particular is what makes this a copy of the *row* rather than a re-save of the
product — "the blue one, size M" is the useful half of what somebody wrote.

Idempotent by product where it can be: a group already on the destination is not
added twice, and the existing row is returned rather than an error, because from
the presser's point of view the item is on the list. A hand-written item has no
`group_id` to match on and is copied each time — two identical hand-written rows
are indistinguishable, and refusing the second would silently ignore a
deliberate press.

Accepted on arrival, whatever the source row was. The approval queue exists for
things a *stranger* added through a share link; this is the list's own owner
deciding to put it there, and asking them to approve their own press is a queue
of one addressed to the person who made it.

## Why a picker rather than drag and drop

Drag and drop was the obvious shape and is the wrong one:

- unusable with a keyboard, invisible to a screen reader;
- on a phone it fights the scroll — a long press that sometimes scrolls and
  sometimes lifts is worse than no feature, on the device most of this happens
  on;
- it cannot say *which* list unless the destination is already on screen, which
  on a phone it never is.

So: a control on the row, and a list of destinations by name.

**The control is an icon on a list row and words in the Ask panel.** On a list
row it sits beside the remove control at the end of every item, and "Kopieer naar
een ander lijstje" was longer than most of the product titles it lined up
against — repeated down twenty rows it read as the page's main verb, which it is
not. In the Ask panel it *is* the point of the row, and it stands beside a text
"Claim" button rather than in a column of glyphs, so it keeps its words. The name
is spoken either way, through `aria-label` and `title`.

The glyph is `ShareIcon`'s `copy` — the same two sheets the share menu uses for
the same idea. A second drawing of one thing is a second vocabulary.

## The saved-items cache

`CopyToList` calls `markSaved()` on success, exactly as `SaveToList` does after a
save. That cache is what every product card on every surface reads to decide
whether it is already bookmarked — and the first version of this did not touch
it, so the product's own page went on reporting "not saved" for a list it was now
on. The visible symptom of writing a second saving path and forgetting the first
one's bookkeeping.

**`SaveToList` itself was considered and does not fit.** It saves a *product*,
not a *row*: hand-written wishes have no `groupId` and could not go through it at
all, the note would not travel, and it posts to the save endpoint, which cannot
express "copy from somebody else's shared list". Adopting it would have meant
keeping both components anyway.

## Where it is

| | |
|---|---|
| Service | [app/Services/Wishlist/ItemMover.php](../../app/Services/Wishlist/ItemMover.php) |
| Endpoints | [app/Http/Controllers/ItemTransferController.php](../../app/Http/Controllers/ItemTransferController.php) — `POST /lists/{list}/items/{item}/copy`, `POST /l/{token}/items/{item}/copy` |
| Component | [resources/js/Components/CopyToList.tsx](../../resources/js/Components/CopyToList.tsx) |
| Rendered | `Lists/Show.tsx` on every item, `ListTools.tsx` on every Ask row |
| Copy | `site.lists.copy_to`, `copy_to_which`, `copied_to`, `add_to_my_list` |
| Tests | [tests/Feature/CopyItemToListTest.php](../../tests/Feature/CopyItemToListTest.php) |

## See also

- [wishlists.md](wishlists.md) — invariant 4, and the claim rules
- [list-surfaces.md](list-surfaces.md) — the Ask panel this feeds
