# Friends

The people you share lists with. Made by following somebody's share link and having an account at
the end of it, or by adding an address on the page.

**Status:** Active

---

## The gap this fills

Sharing has been a link since invitations were dropped. That made it frictionless and left both ends
anonymous to each other: somebody sends you their wedding registry, you sign in to claim something
off it, and neither of you ends up with any record that the other exists. The next occasion starts
from a blank page and a hunt through a chat history for a URL.

`list_opens` already knew half of it — it is what puts anything under Shared Lists — but only in one
direction, and only as a bookmark for the reader. A friendship is the same fact made symmetric and
made visible to both people.

## Two rows, not one ordered pair

A friendship is symmetric and could be stored once with the lower id first. It is stored twice
instead, because every read is "who are *my* friends" and an ordered pair turns that into a union of
two queries against two columns — written correctly once, and then wrongly in the second place that
needs it.

The cost is that every write is two writes and every removal is two removals.
[`App\Services\Social\Friends`](../../app/Services/Social/Friends.php) is the only place that
happens, and it should stay that way: a one-sided friendship is a name that appears on your page and
not on theirs, which reads as a bug on whichever side is missing it.

The two-row shape also buys something. Each row is *one person's own record*, which is what makes
`friend_birthday_day` / `_month` possible — see below.

## Not a permission

Same discipline as `list_opens`, which this sits beside. Access to a list is its share token plus
`visibility != private`, decided by `SharedListController` and nothing else. Nothing reads
`friendships` to work out whether somebody may look at anything, and a friendship left behind after
sharing is turned off grants nothing at all.

This matters most for `show_to_friends`, which people will read as "stop these people seeing my
list". It does not do that. It stops the friend page *listing* it; the link anybody already holds
still works, because revoking a link is what turning sharing off is for, and this must not quietly
become a second, weaker version of it.
`one_list_can_be_kept_off_the_friends_page_without_unsharing_it` holds both halves.

## Two controls, and only one of them is a permission

| control | scope | question | a permission? |
|---|---|---|---|
| `wishlists.visibility` | one list | who can reach this list at all — private, link, public | **yes**, the only one |
| `wishlist_shares` | one list, one person | does it appear on *this friend's* page | no |

### Sharing is an act, not a setting

A list reaches somebody's friends page in exactly two ways, and both are things
the owner did:

- **They shared it with you** — picked your name in "Share with friends", which
  wrote a `wishlist_shares` row and emailed you a link.
- **They sent you the link and you opened it** — `list_opens`, which has
  recorded this since long before friends existed.

Nothing reaches a person who has done neither. That also settles the group gift
without a rule of its own: its audience was always "the people who were sent the
link", which is the second case.

### Two settings were tried first, and both were the wrong shape

Worth recording, because the pull is to add a switch.

**An account-wide `users.friends_see_lists`.** Removed within a day: the per-list
question is where the answer differs, and a master on top meant two controls for
one decision and a page where half the rows were greyed out by the other one.

**A per-list `wishlists.show_to_friends`.** Removed too, and this is the one that
mattered. It meant *everybody I am connected to sees this* — and a friendship
here is made by **opening any share link**, so the audience was a set the owner
had never chosen and could not enumerate. One tap on a box reading "my friends
can see this list" published it to people who had accumulated by accident. The
risk was real and silent.

The general lesson: **a boolean cannot express consent to an audience.** When
the audience is implicit and grows on its own, the only honest control is one
row per person, created by picking a name.

It left one live bug behind on the way out, worth knowing about because the
shape recurs. `show_to_friends` was nullable — null meaning "never asked", with
the kind supplying the default — and the list settings panel sent the **raw
column** to the browser while the friends page sent the **effective** value. An
untouched wish list therefore drew an unchecked box on one page and a ticked one
on the other, and the click meant to turn it on turned it off. A nullable
setting whose default lives in code has exactly one correct thing to put on the
wire, and it is never the column.

### Un-sharing is not revoking

Deleting a `wishlist_shares` row takes the list off that person's friends page.
It does **not** take away a link they already hold — that is `visibility`'s job,
and this must not become a weaker second version of it.
`un_sharing_takes_it_off_their_page_and_leaves_the_link_alone` holds both halves.

### The email

`ListInvitationMail`, revived: it was written for the invitations feature that
was removed and had already argued out the right discipline — a title, who is
asking, and a link, and **no product data**, because a list can be private
research about a third person and mailing its contents would publish that
research to whoever holds the inbox.

Queued, not sent inline, and failure is per person: the share row is written
first and a bounced address must not cost the other four their share. The email
is a nudge; the friends page is the mechanism.

Sharing again with the same person sends nothing — "shared" is a state, not an
event, and a duplicate press is the commonest way to send somebody two of the
same message.

## Both directions, under each name

Each friend row shows what they share with you *and* what they can see of yours. The second is the
half nobody thinks to check — "what does Anna actually see of mine" — and it is the same question the
switches answer, asked where the consequence is concrete: a name, and titles under it.

Each row is collapsed to one line — name, birthday, and two counts — and opens on a click. A friend
list is a list of *people*: the thing you scan is names, and everything under a name is what you want
after finding the one you were looking for. It is a native `<details>` rather than a `useState`, which
buys the keyboard behaviour, the open/closed semantics a screen reader announces, and find-in-page
opening the section that matches.

It is genuinely per person now, because sharing is per person: two lookups — who each list was shared
with, and who opened it — fetched once and indexed by list, rather than a query per friend row.

**Your own lists there link to your own page**, not to the share token. Following one should land you
where you can edit it; the visitor view deliberately carries none of the owner's controls, so sending
an owner there makes their own list look read-only. Stating it once at the top instead ("these are shown to everyone")
leaves people to work out the consequence themselves, which is how a setting ends up misunderstood.
It is also the shape that stays correct if this ever does become per-person.

**The account-wide switch is the master.** Off there means off for everything, whatever an individual
list says — two switches where the specific one could override the general one would mean somebody
who turned the feature off could still be surprised by a list, which is the one thing a master switch
has to prevent. `the_account_switch_is_the_master` holds it.

### One control, and the bug that proved it

`show_to_friends` was briefly editable in two places: each list's own settings panel, and a row per
list on the friends page. The two promptly disagreed, and it was found in the browser rather than by
a test.

The column is nullable, so the panel has to render the **effective** answer — `Wishlist::showsToFriends()`
— and it was sending the raw column instead. An untouched wish list, which friends could already see,
therefore drew an **unchecked** box while the friends page drew a ticked one. Two failures at once:
the panel stated the opposite of the truth, and the click meant to turn the switch on turned it off,
because the box had started in the wrong place. Three lists in the development database were switched
off that way by somebody trying to switch them on.

The friends-page copy is gone and the panel is the only control.
`the_list_settings_draw_the_switch_the_way_the_list_actually_behaves` pins the payload. The general
lesson is the ordinary one: a nullable setting whose default lives in code has exactly one correct
thing to put on the wire, and it is never the column.

## Not claim state

The friend list is one join away from telling somebody's friend what their own list must never show
them, so `FriendController` does not load `Wishlist::items` at all — no count, no badge, no "2 of 8
spoken for". Invariant #4 is about what a list's *owner* may learn, and this page is the friend's
side of it; the way it stays safe is that the question is never asked here.
`the_friend_page_carries_no_claim_state` pins it against the same word list the homepage card test
uses.

Being connected to somebody discloses that they hold one of your links, which they already knew.

## Where a friendship comes from

Two paths, because a visitor is in one of two states and only one of them can be connected to
anybody right now:

| the visitor | what happens |
|---|---|
| signed in, opening a shared link | connected on the read — both ends are accounts, nothing to wait for |
| signed out, opening a shared link | the referral is held in the session, and `LinkSharerAsFriend` applies it if an account appears |
| added by email, address has an account | connected immediately |
| added by email, address has no account | held as a `friend_invite`, applied at that person's first sign-in |

Nothing is recorded against an anonymous visitor who never signs in: half a friendship is a cookie
that will be cleared. An anonymous list *owner* is likewise nobody to be connected to.

`ShareReferral` keeps one referral rather than a queue — the relevant one is the link that made
somebody sign in — and it lives a day rather than `PendingClaim`'s hour, because it only records
that two people are coordinating, which is still true tomorrow. The commonest journey is a magic
link read on a phone hours after the list was opened on a laptop.

### The listener is one, deliberately

`LinkSharerAsFriend` applies both the referral and any waiting invites, on `Login`. Same reasoning as
`ReplayPendingSave` and `ReplayPendingClaim`: there are two sign-in paths today and nothing promises
there will not be a third, and a connection that completes on the magic link but not on Google is a
bug visible only to whichever half of people pressed the other button.

## Adding by email must not answer the question it is asked

"Add a friend by email" wants to report *did that work*, and the honest answer is either "yes, they
have an account" or "no, they do not" — which together make the endpoint a way to test whether any
address you like has an account on a site that holds people's wish lists. Somebody could walk a
mailing list through it and learn who shops here.

So the two cases are indistinguishable from the outside: connected now, or written down and applied
at their first sign-in, and **the same sentence either way**. It is a true sentence in both cases.
The rate limit (`throttle:10,1`) is the second half of that defence — a caller who cannot tell one
address from another still should not be able to walk a list of them.

Nothing is emailed. Sending "Bob added you as a friend" to an address that has never been near this
site turns the feature into a way of mailing strangers on somebody else's behalf, and the connection
is worth nothing until that person arrives of their own accord anyway. When they do, the invite is
waiting.

## Two birthdays, and they are not the same fact

| column | whose fact | year? | who sees it |
|---|---|---|---|
| `users.birthday` | what you publish about yourself | yes, if you give one | your friends, if `friends_see_birthday` is on |
| `friendships.friend_birthday_day` / `_month` | what *you* wrote down about somebody | **never** | you, on your own row |

You may know your brother's birthday; he has not published it. That is your note, and it must never
become his account's answer — a date somebody guessed would otherwise start appearing to everybody
else as fact. The page labels the second case ("your note") for the same reason, so a date you typed
never reads back to you as one he gave.

Their published date wins over your note when both exist.
`a_birthday_you_wrote_down_never_becomes_their_answer` holds both directions.

### The missing year is the point

What anybody needs from a friend's birthday is *when to buy something*, and that is a day and a
month. A year is somebody's **age**, recorded about them by a third party who was not asked and may
well be wrong, on a page other people read.

So the year exists in exactly one place: `users.birthday`, given by its owner, about themselves, on
their own settings. Everything second-hand is `MM-DD`. Adding a friend by email offers two selects
rather than a date picker, because there is no browser control for a date without a year and
`type="date"` would demand the one thing nobody should be typing about somebody else. A full date
posted to that endpoint is **refused**, not quietly trimmed — accepting it would mean the form and
the store disagreed about what is being asked for. `a_year_cannot_be_recorded_about_somebody_else`
holds it.

Their own published date is trimmed on the way **out** rather than on the way in: the year is real
and theirs, it is simply not this page's to show.

Two smallints rather than a string or a date with a sentinel year. A sentinel is a lie that leaks the
moment somebody formats the column, and "whose birthday is next" is a question this shape can answer
later without parsing. 29 February is allowed and the page formats against 2000, which is a leap
year — every other choice turns that birthday into 1 March.
[`App\Support\DayAndMonth`](../../app/Support/DayAndMonth.php) is where the pair is read, written
and put on the wire as `MM-DD`.

## Removing is symmetric too

The part most likely to be argued with later: removing somebody could plausibly leave you on their
page. It must not. The connection is a single fact about two people, and a version of it that one of
them can still see after the other has ended it is exactly the shape of thing people mean when they
say a site kept their data.

Nothing else is destroyed — lists, claims and anything already shared are untouched — and following
the link again re-creates it, which is why there is no server-side confirmation step. The browser
asks, because a single stray tap should not end a connection for two people.

## Personal data

The graph is personal data (who knows whom), and the birthdays on it are second-hand facts about
people who never typed them here. `bc:scrub` therefore **deletes** `friendships` and `friend_invites`
outright rather than anonymising them — nothing joins to a friendship, and a scrubbed one would be a
random pair of test users pretending to know each other — and nulls `users.birthday`.

## Files

| What | Where |
|---|---|
| Both directions of a write | [`App\Services\Social\Friends`](../../app/Services/Social/Friends.php) |
| "Somebody sent me this link" | [`App\Services\Social\ShareReferral`](../../app/Services/Social/ShareReferral.php) |
| Adding by email | [`App\Services\Social\FriendInvites`](../../app/Services/Social/FriendInvites.php) |
| A birthday with no year | [`App\Support\DayAndMonth`](../../app/Support/DayAndMonth.php) |
| Applied at sign-in | [`App\Listeners\LinkSharerAsFriend`](../../app/Listeners/LinkSharerAsFriend.php) |
| The page | [`FriendController`](../../app/Http/Controllers/FriendController.php), `resources/js/Pages/Friends/Index.tsx` |
| Where a friendship is made | [`SharedListController::show()`](../../app/Http/Controllers/SharedListController.php) |
| The per-list switch | `wishlists.show_to_friends`, edited via `WishlistController::update()` |
| Tests | [`FriendsTest`](../../tests/Feature/FriendsTest.php) |

## Related

- [wishlists.md](wishlists.md) — sharing, and why claiming now needs an account
- [list-taxonomy.md](list-taxonomy.md) — what each kind of list is for
- [auth.md](auth.md) — the two sign-in paths this hangs off
