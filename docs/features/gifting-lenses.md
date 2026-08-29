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
| Co-giver | invitations, roles, pooled contributions | `wishlist_collaborators`, `gift_pledges` |
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
| `for_someone` | a `recipient_id` | "help me choose / don't double up" | **yes**, since 2026-08-29 |
| `group` | a `recipient_id` | "we are buying one present together" | **no** — pledges instead |

> **The `for_someone` row said "no" for a year, and the row above it explains why that was wrong.**
> "Don't double up" *is* claiming. What the `no` was really protecting is that claiming must not be
> gated on visibility — which still holds, and is why `group` is not claimable either. Who may *see*
> the claims inverts by kind; see
> [list-taxonomy.md](list-taxonomy.md#claiming-on-a-gift-list-and-the-same-inversion-a-second-time).

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

### Two ways the self-describe page was broken, 2026-08-29

Both reported from a browser, and both invisible to the suite because the tests posted to endpoints
rather than opening the page.

**"This is me" answered 403.** `claim()` has always refused the *giver* — claiming your own stub
would make you the recipient of your own gift research — and `canClaim` asked only "signed in, and
not yet linked". So the likeliest visitor to this page, the giver checking the link before sending
it, was offered a button that failed with no explanation. The page now mirrors the endpoint exactly
and says which side of the link you are on; the endpoint still refuses, because hiding a button
stops nobody hand-building the request. Same defect, and the same fix, as
`allowsContributionsFrom()` and `canSuggest`.

Somebody **signed out** now gets the way in rather than nothing. The token is the credential, so
describing yourself needs no account — but saying "this is me" attaches a person to one, which makes
this the short path to having an account rather than a refusal.

**Searching wiped the page.** `suggest()` rendered `Recipients/SelfDescribe` with *only*
`suggestions`, and the page reaches it through `router.get()` — a full visit, not a partial reload.
Every other prop was therefore replaced with nothing, `person` came back undefined, and the page died
on `person.name`. That is "the search does not work" as a person experiences it.

Fixed by making the payload whole rather than by asking the client for a partial reload.
`only: ['suggestions']` would also have worked and is one property short of correct: **a URL that
renders a broken page when somebody refreshes it, or opens it from their history, is broken.**
`/for/{token}/suggest?q=…` is a real address and has to stand on its own, so `page()` builds the
whole payload and both routes use it.

**And the questions were in the wrong person.** The page reused `gift.step_interests` and its two
siblings — copy written for the Gift Whisperer, where you describe *somebody else* — so it asked the
reader "what are **they** into?" about themselves. `recipients.step_*` is a second set in the second
person; the wizard keeps its own, because there the third person is right.

### Signing in without leaving the page

`Components/SignInDialog` is the same two ways in as the login page, in a `<dialog>` over whatever
you were doing. The places that need it are places somebody has already arrived with an intention —
saying "this is me", keeping a product, claiming something — and navigating away throws that
context out. `wishlists.md` records what that cost the save path before `PendingSave` existed; a
dialog avoids the crossing rather than carrying the intent across it.

Native `<dialog>` with `showModal()`, not a div: focus trapping, Escape, an inert page behind and a
top-layer backdrop, all of which a hand-rolled overlay gets wrong for exactly the people who would
notice. `auth.googleEnabled` moved to the shared Inertia props, because signing in is no longer
something that only happens *on* the login page and the Google button must stay hidden when the
client id is unset.

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

**Was deferred, now built — by removing the fetch rather than sanitising it.**
The original note said: feed affiliate URLs are already hostile input (invariant
#5), a user-pasted URL is worse, and rendering a remote image and title from it
turns a list into an open-redirect and SSRF surface. All true, and every word of
it is about *fetching* the URL.

So `ItemSaver::saveManual()` does not. The person types the title and the price;
we store the link and never request it. That deletes the SSRF surface instead of
filtering it, and costs one more field to somebody who is already typing.

Three rules make the rest safe:

- **`https:` only, decided in one place.** `WishlistItem::isSafeExternalUrl()` is
  the same test `Product::hasSafeAffiliateUrl()` applies to feed URLs. The
  validator uses it to produce a sentence a human can act on, the saver re-checks
  it on write, and `externalUrl()` re-checks it on read — so a row that predates
  the rule, or arrives through a future import, still cannot reach an `href`.
  Escaping is not a defence here: `javascript:alert(1)` survives HTML escaping
  intact and runs on click.
- **No image, at all.** An image URL would be fetched by every visitor's browser
  from a host the list owner chose — an on-by-default tracking pixel reporting
  who opened the list and when, on the one page in the product whose purpose is
  that the owner learns nothing. Manual items show no picture.
- **`nofollow noopener noreferrer`, and not through `/go/`.** The redirector is
  for affiliate links, where the scheme check and click logging live; laundering
  a stranger's link through our own domain is not what it is for.

`externalUrl()` is scoped to `manual` rows, because every other item's
`snapshot_url` is our own product page stored as a root-relative path with no
scheme — running those through the check would unlink the entire catalogue half
of the list.

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

### And then says how to work it

Landing on the right form is not the same as knowing what to do with it. A card answers *what is
this for* in one sentence — the question somebody scanning nine cards is asking — and stops there,
which leaves "invite other people onto a gift list so several of you can choose together" as a true
sentence that names no control.

So the page has a second layer: a manual below the grid, one entry per tool, **three steps and
nothing else**. The two are written for different readers, which is why they are not merged. Putting
the steps on the cards makes nine tools into a wall of instructions and buries the one-line version
the scanner came for.

Three rules hold it together:

- **A step quotes the label that is really on the screen** — "press Share", "press People", "press
  Do the draw". Describing a control by its purpose instead of its name sends somebody hunting for a
  button they are looking straight at. The corollary: renaming a control silently invalidates the
  step that names it, and only a human reading the page will notice.
- **Three steps, no fourth line.** Caveats were drafted beside them and taken back out — the
  permanence of a handover, the draw closing the group, an address only a claimer sees. Every one is
  true and each is enforced by the tool whether or not this page mentions it, and an entry that runs
  past the point where the reader could have started is one they stop reading.
- **Not an accordion.** Collapsed steps are steps nobody reads, and hiding the longer answer behind
  a second press reproduces exactly the problem the manual was written to solve.

`every_tool_on_the_gift_cove_has_its_three_steps` guards the shape from the direction
`LocalisationTest` cannot see: that test catches a language falling behind English, this one catches
a tenth tool added to the page with no steps written for it. A missing string renders as its own key
— `gift_cove.quiz_step2`, in the middle of a numbered list.

### The icons are drawn, not typed

`resources/js/Components/ToolIcon.tsx` — nine line icons on one 24px grid, `currentColor`, one
stroke weight, `aria-hidden` because the tool's name sits in words beside every one of them.

Emoji were the obvious cheaper answer and fail three ways at once: the glyph is rendered by the
reader's operating system, so it is a different picture on Windows, Android and iOS; it arrives with
its own colours into a palette that has one accent; and **half of these tools have no emoji at all**
— there is no glyph for "a list you hand over" or "invite a co-giver", so the set would have come
out part pictogram and part shrug.

What each one depicts is a decision, not decoration. A gift list gets a *clipboard*, because it is
research you keep about somebody rather than a present — a gift box would promise the wrong thing.
Secret Santa gets *crossing arrows*, because what the feature is is the draw; a hat would be a
picture of the season instead of the mechanism.

The same drawing appears on the card and on the manual entry, and that repetition is load-bearing:
it is what tells a reader who scrolled down that the entry they are reading belongs to the card they
pressed.

---

## Files

- `app/Enums/` — `ListKind`, `RecipientStatus`, `TasteSource`, `SantaStatus`
- `app/Services/Gift/` — `TasteBrief`, `SuggestionEngine`, `SuggestionProfile`,
  `Suggestion`, `GiftTarget`, `SecretSantaDraw`, `DrawImpossible`, `QuizBuilder`
- `app/Services/Wishlist/ItemSaver.php`, `app/Support/ListAccess.php`
- `app/Services/Social/FollowGraph.php`
- `resources/js/Pages/GiftCove.tsx`, `resources/js/Components/ToolIcon.tsx`
- `app/Http/Controllers/` — `RecipientProfileController`, `SecretSantaController`,
  `ListQuizController`, `WishlistCollaboratorController`
- `app/Jobs/SendOccasionReminders.php`
- `tests/Unit/SecretSantaDrawTest.php`, `tests/Feature/{RecipientProfile,SecretSanta,ListQuiz,WishlistCollaborator,OccasionReminder}Test.php`

See [secret-santa.md](secret-santa.md) and [list-quiz.md](list-quiz.md).


## The manual said things the code did not do

Audited and fixed 2026-08-16. The rule that a step quotes the label actually on the screen is a good
one and a fragile one — renaming a control silently invalidates the step that names it, and only a
human reading the page notices. What the audit found:

| Claim | Where the fix went |
|---|---|
| `collab_step1`: "press **People**" | **The label.** The tab read "Who else can see this" — a sentence in a row of one-word chips, and wrong for a group list where those people are co-organisers. The sentence survives as the panel hint. |
| The `collab` card opened a *create* form while its step said "open a list you made" | **Both.** Buying together genuinely starts with a new list, and since group lists became creatable that list is a group one — so the card opens `?new=group` and the step names that choice. |
| The `handover` card opened a create form while its step said "open the list" | **The link.** There is no single such list, so it goes to My Lists. |
| The `suggestions` card pointed at `/lists`, which said nothing about suggestions | **The index, not the link.** The destination was right; the page just never mentioned them. It now carries a waiting badge — owner-only, and absent on a list somebody else owns, because a suggestion is a message addressed to its owner. |
| `suggestions_step2`: "with the name of whoever sent it" | **A fallback.** A suggestion from an anonymous cookie identity has no name and rendered nothing at all. A message from nobody is worse than one from somebody unnamed, and the accept/dismiss decision is largely a judgement about the sender. |

`CopyMatchesCodeTest` is the human, for the claims that can be checked mechanically.
