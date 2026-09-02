---
name: List board
area: Gifting / Coordination
status: Active
date_added: 2026-09-01
---

# The discussion beside a shared list

Everything the people around a list needed to say to each other happened
somewhere else: the group chat the link was pasted into. So the page that knows
what has been claimed, what the pot stands at and what is still unspoken-for
could not answer "shall we go halves on the coat?", and the conversation that
decides the buying ran in a window with none of the facts in it.

A short thread in a rail beside the list, on both pages a list has —
`/lists/{id}` for its owner and `/l/{token}` for everybody holding the link.

## Who may read it is the claim gate

A board is free text written by the people doing the buying, and *"I've got the
scarf, someone take the boots"* is claim state in prose. So it hangs off
`Wishlist::shouldHideClaimsFrom()` — where invariant 4 lives — rather than off a
second rule that would have to be kept in step with it.

| the list | its owner | anybody with the link |
|---|---|---|
| a wish list, claims hidden (the default) | **no board at all** | reads and writes |
| a wish list, owner asked to see claims | reads and writes | reads and writes |
| about somebody else | reads and writes | reads and writes |
| a group gift | reads and writes — they are the organiser | reads and writes |

The first row is the one the feature exists to get right. The owner of a wish
list is the person being surprised; showing them a thread in which their friends
divide up the shopping would undo the feature the list is for.

**The last row needed saying out loud.** `shouldHideClaimsFrom()` alone hides the
board from the organiser of a group gift, because
`ListKind::ownerSeesClaimsByDefault()` is true only for `for_someone` — a default
that predates group lists having a pot. On a group list the owner *is* the
organiser and the recipient is a third party who never opens the page, so
`Board::visibleTo()` spells the gate exactly as
`Wishlist::allowsContributionsFrom()` spells it:

```php
$list->kind->ownerSeesContributions() || ! $list->shouldHideClaimsFrom($viewer)
```

Deliberately the same phrasing. That method solved this problem for the pot, and
a board and a pot are visible to the same people for the same reason — a second
phrasing of one rule is the thing that drifts.

**A private list has no board.** Not because anything would leak — nobody else
can reach it — but because a discussion with one participant is a notes field,
and there is one of those under the title.

## One board, not comments per item

A list is six cards, and a thread under each turns a page you scan into six
pages you read. The same reason the per-item pledge was dropped: the
conversation is about the list.

## It answers JSON

Both endpoints return JSON when the caller asks for it — the created row, or
`{ok: true}` — and the component appends or splices it in place. A conversation
that reloads the page after every line is not a conversation, and nothing else
on the page changes because somebody typed a sentence: the products, the pot and
the claims are all exactly as they were.

The redirect stays for a caller that did not ask for JSON. It costs one line and
it is what makes the form work with the script gone.

`Board::present()` is public for exactly this: the POST answers with the row it
just created, and a message shaped one way in the page payload and another way
in the endpoint is two shapes to keep in step.

## Deletion is the moderation control, not screening

`Community\PostScreen` holds anything carrying a link or a phone number. That is
right on a public board answered by strangers and wrong here — this is a handful
of people who were sent a link by a friend, and "call me on 06…" is the ordinary
case rather than the abuse.

The author may take their own message back; the list's owner may remove any of
them. Both asked again at the endpoint, because the `removable` flag decides a
button and nothing more.

## The row shape

Dual identity like every other row a visitor writes here — `user_id` xor
`anon_id`, a CHECK enforcing exactly one, both cascading — because a list works
before signup and the typical author is an anonymous cookie identity.
`display_name` is typed per message rather than read from the account, for the
reason the pledge does it: the people on a shared list know each other by first
name, and half of them have no account to take one from.

`ListMessage::$hidden` keeps the identity columns off any accidental
`->toArray()`. It matters more here than on most rows: a board is written by the
people buying the presents, so `user_id` beside a message naming what somebody
has bought is claim state with a person attached.

Fifty messages, oldest first. A rail is not a chat client, and the cap is there
so the one conversation that goes wrong cannot make the page enormous.

## Where it is

| | |
|---|---|
| Table | `list_messages` — [migration](../../database/migrations/2026_09_01_000200_a_shared_list_gets_somewhere_to_talk.php) |
| Model | [app/Models/ListMessage.php](../../app/Models/ListMessage.php) |
| Rule | [app/Services/Wishlist/Board.php](../../app/Services/Wishlist/Board.php) |
| Endpoints | [app/Http/Controllers/ListMessageController.php](../../app/Http/Controllers/ListMessageController.php) — `POST/DELETE /{market}/l/{token}/messages` |
| Component | [resources/js/Components/ListBoard.tsx](../../resources/js/Components/ListBoard.tsx) |
| Pages | `Lists/Show.tsx`, `Lists/Shared.tsx` — the rail, and the header lifted above the grid |
| Copy | `site.board.*` |
| Tests | [tests/Feature/ListBoardTest.php](../../tests/Feature/ListBoardTest.php) |

## See also

- [wishlists.md](wishlists.md) — invariant 4, and the claim rules this gate reuses
- [list-taxonomy.md](list-taxonomy.md) — the three kinds, and why the group one inverts
- [list-surfaces.md](list-surfaces.md) — the two pages a list has
