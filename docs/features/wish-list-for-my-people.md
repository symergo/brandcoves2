---
name: A wish list for my people
area: Wishlist / Gifting
status: Active
date_added: 2026-09-26
---

# A wish list your people can see and pick from

The owner's request of 2026-09-26: "Set an option so that you can make your wish list available to
your circle to be inspired on what to buy for you. When others build a list for you, they will be
able to pick from your shared wish lists."

"Your circle" is **My people** ([my-people.md](my-people.md)). Only the friends there have
accounts and lists, so in practice the audience is **your friends on GiftCoves**: people you are
connected with by opening each other's shared list or by adding each other by email
([friends.md](friends.md)). People you only saved (a `recipients` row, most without an account)
never see anything of yours.

## What it does

A wish list (`kind = mine`) has one more setting, in its Share panel:

| | en | nl | fr | es |
|---|---|---|---|---|
| the option | Visible to my people | Zichtbaar voor mijn mensen | Visible par mes proches | Visible para mi gente |

While it is on, every friend of the owner can:

- see the list on the owner's row on **My people** (it is one of "their lists"), and open it at
  `/l/{token}`, the ordinary shared-list page;
- say "I'll get this" on an item, exactly as a link holder can (the owner never sees it);
- **pick from it** when they make a list about the owner: see below.

The (i) beside the option says who sees it and who does not, and the line under it names them
("Now: Ben, Carl"), the same names "Share with friends" offers.

## Why a switch after all

[friends.md](friends.md) records that a per-list `show_to_friends` switch was built and pulled on
2026-09-06: a friendship is made by opening any share link, so "my friends" was an audience that
accumulated by accident and that the owner could not see, and "a boolean cannot express consent to
an audience". The owner has now asked for exactly that switch, for the case it fits: a wish list
exists to be bought from, and the people most likely to buy from it are the ones you are connected
to. Two things answer the old objection:

- **The audience is shown.** The names are under the switch, on the list itself. The old switch
  said "my friends can see this" and named nobody.
- **It is only on a wish list of your own.** A list about somebody else is research about a third
  person and a group list has its own audience (the people sent its link). The model clears the
  column on every other kind (`Wishlist::booted()`), so a list that changes kind cannot carry it.

## Independent of the link

`visibility` stays what it was: who may open the list **by its link**. This option is a second
audience beside it, not a kind of link:

| visibility | visible to my people | who can open `/l/{token}` |
|---|---|---|
| private | off | nobody but the owner (on their own page) |
| private | **on** | the owner's friends, and nobody else, even with the link |
| link | off | anybody with the link |
| link | on | anybody with the link; the friends also find it on My people without being sent it |

That is why a new wish list can have it on without being "shared": it gets no working link for
strangers. `Wishlist::scopeReachableBy()` is the one place both audiences are asked, and every
endpoint reached through a share token uses it: reading the list, claiming, unclaiming, "sent"
(`SharedListController::findShared()`), suggesting (`SuggestionController`) and copying an item
(`ItemTransferController::fromShared()`). Voting and chipping in are group-list only; the quiz, the
discussion board and the link preview image stay link-only, because they are about the link.

`Wishlist::hasCoGivers()` counts it, so a private wish list with the option on can be claimed from
(claiming needs somebody besides the owner, and the friends are that somebody).

## The default

- **On for wish lists made from now on**: a new wish list (`ListMaker`) and a new default list
  (`DefaultList`), for an owner with an account. Decided without the owner, from the request
  ("to be inspired on what to buy for you") and the brief.
- **Off for every list that existed before**, including an old list adopted as the default one.
  Those lists were made private or shared by somebody who never saw this option, and switching it
  on for them would put them in front of people they did not choose. The migration adds the column
  as `NOT NULL DEFAULT false` and backfills nothing.
- **Never** for an anonymous owner (no friends) or for a list about somebody else.

A plain boolean, not a nullable "never asked": friends.md describes the bug a nullable setting with
its default in code caused (the raw column on one page, the effective value on another). Here the
stored value is the answer, and `list.visibleToFriends` on the wire is `true`, `false`, or `null`
where the question does not arise.

## Picking from it

On a gift list or group list whose person is linked to an account (`recipients.user_id`, set when
the list is made "for one of my friends", from My people's "Save what you know", or when the person
claims their `/for/{token}` link), the page shows **"From Anna's wish list"** under the items, and
the same block inside "Add a product" while nothing has been searched. Each wish has one tap:

- **Add to my list** copies it onto this list through the existing copy path
  (`POST /l/{token}/items/{item}/copy`, `ItemMover`). The product (or the hand-written item's
  title, link, price and picture) comes along; **the owner's note does not** (`keepNote: false`):
  what Anna wrote under an item ("size M") is addressed to the people reading her list, and on
  Ben's list it would read as Ben's own words and travel on to whoever Ben shares with. A claim
  never travels, as before. Copying twice does not duplicate a product.
- **I'll get this** claims it on her list, through the same endpoint as her list's page, on the
  list page only. Claiming is a decision about buying, not about filling a list, so the add panel
  does not offer it.

A wish whose product is already on this list says "On your list" instead of offering itself again.
The section sits under the items: on a gift list the owner opens to work on their own picks, the
first thing under the title must be theirs (list-surfaces.md, "Items first").

### Which of her wish lists: `GiftTarget::wishesSeenBy()`

The section is the old "what they asked for" lane, which lived behind More > Ask. That lane read
**every link-shared wish list** of the linked person (`statedWishes()`), whether or not the giver
had ever been sent it. It now reads only what the owner did for this giver:

- "visible to my people" is on **and** the two are friends; or
- it is link-shared **and** was shared with this giver by name or its link was opened by them
  (the two ways a list reaches their My people row).

So a person who merely linked a saved person to Anna's account, without being her friend, sees
nothing of hers here. This narrows what some existing givers saw; it is what the option exists
for. Secret Santa still uses `statedWishes()` for a drawn giftee, whose group is its own consent.

The Ask tool is no longer offered for a linked person with wishes to show (the section replaces
it); with nothing to show it says so, and that sentence now names the option.

## Privacy

- **Friends only, mutual.** Friendships are two rows written together; the check reads the
  owner's row (`friendships.user_id = owner`). Removing a friend deletes both, and the list is gone
  from them on the next request.
- **Switching it off takes it away at once**, from My people, from the pick section and from
  `/l/{token}` on a private list. A friend who opens the list through the option does **not** get a
  `list_opens` bookmark, because a bookmark would keep the list under their lists after the owner
  had taken it away. What remains: on a list that is *also* link-shared, a friend who kept the URL
  can still open it, like anybody holding the link, until the owner stops sharing. The (i) says so.
- **No claim state reaches the owner** (invariant 4). Nothing here changes who sees claims:
  `shouldHideClaimsFrom()` still hides them from the wish list's owner, the owner's page carries no
  claim fields, and the discussion board (claim state in prose) stays off a private list.
- **Nothing private of the owner's reaches the giver.** The giver sees what the shared page shows
  any reader of the list: items and their notes. Notes are not copied.
- **Market independent.** A list belongs to a person, not to a market
  (`MarketIndependentListsTest`); a friend opens and picks from a list made on `nl-nl` while
  reading `en` or `es`.

The privacy page (en, nl) has a paragraph "Your friends and your wish lists".

## Where it is

| | |
|---|---|
| Column | `wishlists.visible_to_friends`, migration `2026_09_28_000200_a_wish_list_your_people_can_see` |
| Rules | `Wishlist::isVisibleToFriends()`, `scopeVisibleToFriend()`, `scopeReachableBy()`, `hasCoGivers()` |
| Default | `ListMaker::visibleToFriendsByDefault()`, used by `ListMaker::make()` and `DefaultList::for()` |
| Setting | `PATCH /{market}/lists/{id}` with `visible_to_friends`; the Share panel in `ListTools.tsx` |
| My people | `MyPeople::sharedWith()` (their lists) and `seenBy()` (what they see of yours) |
| Picking | `GiftTarget::wishesSeenBy()`, `WishlistController::asked()`, `Components/TheirWishes.tsx` on `Lists/Show` and in `AddProduct` |
| Copy | `ItemTransferController::fromShared()`, `ItemMover::copy(keepNote: false)` |
| Copy text | `site.lists.visible_to_people*`, `sharing_off_people`, `their_wishes*`, `on_your_list`, `asked_none`; `/help` has `help.share_people` |
| Tests | `WishListForMyPeopleTest`; `RecipientProfileTest` (the lane now needs the option and a friendship) |

## Not done

- The Lists overview (My Coves) does not list a friend's visible wish lists under "For others";
  they are on My people. Adding them there means widening `ListAccess::scope()`, which is also the
  rule for what somebody may *edit*, and that deserved its own decision.
- No email or notification when a friend's wish list becomes visible to you.
- Secret Santa's giftee lane still reads every link-shared wish list (`statedWishes()`).
