---
name: Gifting — one list, many lenses
area: Gifting / Core
status: Active
date_added: 2026-08-09
---

# One list, many lenses

**The wishlist is the noun. Secret Santa, the quiz, the two-lane gift page, the
co-giver roster and the occasion reminder are verbs applied to it.**

This is the organising decision behind everything in this document, and it is
worth stating plainly because the alternative is what v1 did: five features that
each grew their own storage, their own sharing, and — the expensive one — their
own idea of what "claimed" means.

| Lens | What it adds | What it reuses unchanged |
|---|---|---|
| Receiver — "what I want" | self-description, multi-source picking | `wishlists`, `wishlist_items`, `SearchService` |
| Giver — "what I found for you" | the two-lane page | items, snapshots, `Owner` scoping |
| Co-giver | invitations, roles | `wishlist_collaborators` |
| Secret Santa | a group, a draw, an assignment | *the entire list + claim system* |
| Matching game | rounds, distractors, a share grid | list membership, `GuessBand`'s discipline |
| Occasion | a trigger, a scoring signal | `recipients`, `notifications` |

Three consequences hold everywhere:

1. **One item table, one claim mechanism, one privacy rule.** The realistic way
   invariant #4 breaks is not a bad line of code — it is the fifth feature
   growing its own copy of "is this claimed".
2. **Nothing overloads `wishlists` to mean something it isn't.** A Secret Santa
   *group* has no items, so it is not a list. A quiz is not a list. Otherwise
   `SharedListController::findShared()` could serve a non-list.
3. **"Who am I shopping for" is an abstraction** — `GiftTarget` — not a column
   read. It resolves from a recipient, a Secret Santa assignment, or nobody.

---

## The bug this work started from

`Wishlist::shouldHideClaimsFrom()` took a `?User` and returned `false` for null.
`SharedListController` passed `$owner->user`, which **is** null for an anonymous
owner — and lists are anonymous-first by design, so this was invariant #4 failing
in the *ordinary* case:

> An anonymous visitor creates a list, shares it, and reopens their own
> `/l/{token}` — and is shown exactly what has been claimed.

It now takes an `Owner` and compares both owner columns. The test
(`an_anonymous_owner_never_sees_claim_state`) was written first and observed
failing; one written afterwards would have proved nothing.

## `kind` replaced `is_gift_list`

`wishlists` carried both `is_gift_list` (boolean) and `recipient_id` (nullable),
answering overlapping questions and able to disagree. So claiming ended up gated
on *visibility* instead, which made **every shared list claimable — including
someone's private research about their own mother**.

| `kind` | Subject | A shared link means | Claimable |
|---|---|---|---|
| `mine` | the owner | "here is what I'd like" | **yes** |
| `for_someone` | a `recipient_id` | "help me choose / don't double up" | **no** |

The recipient decides the kind; there is no separate switch that can contradict
it. `Wishlist::allowsClaiming()` is the one place any lens asks.

---

## A recipient became a person

v1 modelled this and v2 lost it, which is why gifting here had only ever had one
participant. `recipients.share_token` was minted on every row from the beginning,
with a comment saying it let the recipient fill in their own tastes — and no
route ever resolved it.

`recipients` now carries `user_id` and `status` (`stub | linked | self`), and
`/{market}/for/{token}` is live.

### Two kinds of fact, owned by two different people

Conflating them is how one of them gets destroyed:

- **Giver context** — relationship, occasion, birthday, notes, budget. *My*
  situation, not theirs. `notes` is `$hidden` because it is written *about* them.
- **Taste** — interests, vibe, values, avoid. Theirs. I may guess, but the moment
  they tell me, their answer is simply better evidence.

`TasteSource` records who last wrote the taste block. A guess is `suggested` and
freely revisable; once the person answers it is `self` and
`Recipient::describeTaste()` silently ignores later guesses. Silently, not with
an error — the owner is not doing anything wrong by holding an older opinion.

**The page does not prefill what the giver guessed.** Showing "we heard you like
gardening" reveals what they have been told about and anchors the answer to
somebody else's idea of them.

### The link is a capability

`/for/{token}` grants describing yourself and curating your own list, and nothing
else. Same security model as `/l/{token}`; it is not a login and must not become
one. The first identity to open it owns the resulting list — user, or the
anonymous cookie identity — so the single-owner CHECK constraint stands and
`IdentityMerger` folds it into an account later exactly as with any other
anonymous list.

**The rule for that page:** *it shows the recipient's own list and never anything
the giver did.* No claim state on their own items, no view of the giver's
`for_someone` list, no count of what has been picked.

---

## Two engines, one pipeline

Search and the Gift Whisperer are not two engines with a wall between them.
`AngleMap` already turned an interest into concrete product nouns and fed them
to the *same* tsvector index `SearchService` queries — the whisperer was always a
search whose query was written by a brief.

| Stage | Search | Whisperer | Shared? |
|---|---|---|---|
| Query | typed | derived from the brief | different input, same output |
| Retrieve | tsvector + trigram + live bol | the same | **yes** |
| Hard filters | market, in stock, giftable | + budget, `avoid` | **yes** |
| Rank | relevance | five weighted signals | no |
| Save | `WishlistItemController::store()` | the same | **yes** |

`TasteBrief::searching()` folds a typed query in, first, ahead of every derived
angle — someone who wrote "espresso tamper" has said precisely what they want.
The win that matters is **`avoid` now binds to search too**: "no alcohol" used to
hold on the suggestions page and quietly stop holding the moment somebody used
the search box, which is not a hard filter.

### One ranker, two profiles

`GiftEngine` was named after one of its two jobs. Describing yourself and
describing someone else are the same operation over the same catalogue, so it is
now `SuggestionEngine` taking a `SuggestionProfile`.

**The difference that would have been invisible:** `budget_fit` peaks at 85% of
the ceiling because *a cheap gift reads as thoughtless*. That is a fact about how
a present is received between two people. Nobody thinks their own €12 wish is
thoughtless — so `for_myself` uses a flat in-range shape. Reusing the curve
unchanged would have quietly buried every affordable thing on your own wishlist
and looked exactly like a working feature. `the_self_profile_does_not_penalise_a_cheap_item`
is the test that would catch it coming back.

`SuggestionProfile` is deliberately shaped like a Mode Profile from
`config/discovery.php`, so folding these into the discovery dial later is a data
change rather than a rewrite. The brief retriever is also the exact prerequisite
`discovery-modes.md` named for turning on the `advisor` mode — that is now a
decision to evaluate rather than a rewrite. Ranking should converge on
measurement, not tidiness: the two objectives are different maths, and swapping
blind would discard what makes the whisperer good.

---

## Saving from every source

`WishlistItemController::store()` required a stored `ProductGroup`, so a live bol
result or an Amazon product could be *searched for* and then not saved. The
schema had anticipated this from the start — `group_id` nullable, unique index
partial.

**Two rules point in opposite directions, and the source decides which applies:**

| Rule | Says |
|---|---|
| snapshots, not references | freeze title, image and price at add time |
| invariant #6 — Amazon may not be mirrored | store the decision only; re-fetch at render |

`ItemSaver` gates on `Source::allowsCatalogueStorage()`. Feed and bol products
snapshot; an Amazon pick stores `amazon_asin` and **no** title, image or price,
rendering live and hiding on a failed fetch — following the `daily_picks.amazon_asin`
precedent rather than inventing a second convention. Consequence, documented
rather than hidden: an Amazon item cannot show "you saved it at €329" and cannot
carry a price alert.

The snapshot fields in the request are **hints, not instructions** — `ItemSaver`
decides per source whether any may be stored, so a hand-built POST naming Amazon
cannot smuggle a mirrored title into the catalogue.

**Deliberately deferred: pasting an arbitrary product URL.** Feed affiliate URLs
are already hostile input (invariant #5); a user-pasted URL is worse, and
rendering a remote image and title from it turns a list into an open-redirect and
SSRF surface. The `manual` source is the seam when that is wanted; it needs a
fetch-and-sanitise design, not a `nullable|url` rule.

## The Gift Cove starts the tool it describes

The hub explains nine tools, and six of its cards pointed at `/{market}/lists`. Reading "a list you
build for somebody and then hand over to them" and pressing it got you an index of your existing
lists and no indication which button began that — a card that teaches the vocabulary and then
withholds the verb.

The three that begin with a list *about someone* (gift list, co-giving, handover) now open the
create form already on that shape, via `?new=for_someone`. The ones that act on a list you already
have (registry, quiz) open that list, where their panel lives. Suggestions is a thing other people
send you, so the index — where you see which list received one — remains the honest destination.

`?new=for_someone` is read from `usePage().url`, not `window.location`; see
[sharing.md](sharing.md) for why that distinction is load-bearing.

---

## Files

- `app/Enums/` — `ListKind`, `RecipientStatus`, `TasteSource`, `SantaStatus`
- `app/Services/Gift/` — `TasteBrief`, `SuggestionEngine`, `SuggestionProfile`,
  `Suggestion`, `GiftTarget`, `SecretSantaDraw`, `DrawImpossible`, `QuizBuilder`
- `app/Services/Wishlist/ItemSaver.php`, `app/Support/ListAccess.php`
- `app/Services/Social/FollowGraph.php`
- `app/Http/Controllers/` — `RecipientProfileController`, `SecretSantaController`,
  `ListQuizController`, `WishlistCollaboratorController`
- `app/Jobs/SendOccasionReminders.php`
- `tests/Unit/SecretSantaDrawTest.php`, `tests/Feature/{RecipientProfile,SecretSanta,ListQuiz,WishlistCollaborator,OccasionReminder}Test.php`

See [secret-santa.md](secret-santa.md) and [list-quiz.md](list-quiz.md).
