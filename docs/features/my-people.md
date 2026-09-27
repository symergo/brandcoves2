---
name: My people
area: Accounts / Gifting
status: Active
date_added: 2026-09-26
---

# My people ("Mijn mensen")

One page, `/{market}/people`, listing everybody the user buys for. Named "Mijn mensen" (en "My
people", fr "Mes proches", es "Mi gente"); the owner's first name for it was "Mijn kring" and was
changed the same day.

## Why one page instead of two

Until 2026-09-26 "the people in my life" lived in two places:

- **Friends** (`/friends`): other GiftCoves accounts you are connected to. You see the lists they
  share with you and the birthday they show.
- **Saved people** (`recipients`): people you buy for, most without an account. Their relationship,
  birthday, interests, budget, gift history and next steps. They had a page each
  (`/people/{id}`, [gift-history.md](gift-history.md)) but no page listing them.

The owner's audit asked "wat is het verschil tussen mijn mensen en vrienden?" ("what is the
difference between my people and friends?"). For a visitor there is none worth knowing: both are
somebody you buy a present for. The difference is only where the facts come from, so the page
shows one list and marks the people who are on GiftCoves themselves with a small "op GiftCoves".

**Mijn mensen and Mijn Coves point at each other** (owner, 2026-09-27): a secondary "Mijn Coves"
button (heart) in this page's header, and a "Mijn mensen" button (two figures) beside "Maak een Cove"
on `/lists`. Who you buy for and what you collect are the two halves of the same job.

## What a row shows

- the name (your own name for them when you saved them), and the relationship in the reader's
  language when it is one of the closed vocabulary (`mother` shows as "Mama"); a relationship typed
  by hand shows as typed, and one that equals the name is not repeated;
- the next date: the nearest of their birthday and the occasion date on a list about them, with
  "vandaag", "morgen" or "over N dagen". A birthday carries the `cake` line icon (`ToolIcon`), not
  the 🎂 emoji it had until 2026-09-27: an emoji is the reader's operating system's picture, in its
  colours, beside line icons in ours;
- one line of what you know, for a saved person: up to three interests in the reader's language
  and "+N" for the rest, your budget ("tot €50", "vanaf €20", "€20 tot €50"), and how many lists
  you are making for them (kind `for_someone` or `group`, owned by you, about this person). A part
  with nothing in it is left out, and so is the whole line when all three are empty: the owner's
  rule is no empty blocks;
- the name, and the whole left part of the row, links to the person's page (`/people/{id}`). The
  "Hun pagina" button that did the same went on 2026-09-27;
- for a saved person, **one** button: **Cadeau vinden** (`/gift?for=<id>`, Find a gift straight on
  their ideas). The rest sit in a **Meer** menu, the same `Menu`/`MenuItem` component the list
  page's Meer menu uses: **Dit of dat** (`/gift/taste?person=<id>`), **Vraag** (`/ask?person=<id>`:
  Ask others, the form opened and filled in with their relationship, interests, style and budget,
  never a name or a note; added 2026-09-27 at the owner's request), **Dit of dat samen** only
  while a This-or-that-together link is open for them (it goes to the list about them, where that
  panel lives), and **Nodig uit op GiftCoves** while no account is behind them (see
  [Inviting a saved person](#inviting-a-saved-person)). Four equal buttons on every row made the page a wall of buttons where the names
  should lead (owner, 2026-09-27);
- for a friend: their lists, **visible without a click**, drawn with `ListName` (the list-name
  style, with the kind's icon). They were behind a "Hun lijsten (N)" toggle, which hid the one thing
  a friend is on this page for. **Details** still opens what is about the connection: which of
  yours they see, your birthday note about them, and removing the connection.
  Since 2026-09-26 "their lists" includes every wish list they made "visible to my people", and
  "which of yours they see" every one of yours
  ([wish-list-for-my-people.md](wish-list-for-my-people.md)). Since 2026-09-27 "their lists" are
  their **own wish lists only** (kind `mine`): a list they make for somebody else sat among them and
  read as if they wanted what was on their grandfather's list. Those lists, and its date, moved to
  "Samen met" (below), counted in the line under the name as "samen: N";
- for a friend you are in a **Secret Santa** with: a small mark beside "op GiftCoves", with the
  `santa` line icon, the group's name and its day (owner's addition, 2026-09-27). Only a group whose
  day is still ahead, or has none yet: last year's office draw is history, not news. Membership
  only, never the draw (see [Samen met](#samen-met-naam));
- for a friend nobody saved yet: **Bewaar wat je over [naam] weet**, which creates a saved person
  linked to their account through the existing `POST /recipients` with `friend_id`
  (`RecipientController::store`, which checks the friendship). From then on the row has everything
  a saved person has.

Sorted by the next date, then by name (accents folded). People without a date come last.

## The person's page

`/{market}/people/{id}` (`PersonController::show`, `App\Services\Social\PersonProfile`,
`Components/PersonProfile.tsx`). Until 2026-09-27 it was titled "Cadeaus voor Mama" and showed two
buttons and the gift history; everything the site knew about her could only be seen inside Find a
gift, and none of her lists were there. The owner asked for a page about the person. Now, in order,
and each part left out when it would be empty (the owner's rule: no empty blocks; one column, full
width, because there is nothing for a side column):

1. **The person**: their name as the heading; under it the relationship, the birthday (`cake`
   icon) or a "Verjaardag toevoegen" link when there is none, and their status: "op GiftCoves"
   for a friend, or **"Niet op GiftCoves · Nodig uit"** (see
   [Inviting a saved person](#inviting-a-saved-person)). Under that, the ways to an idea:
   **Cadeau vinden** (the one filled button; a full row on a phone), **Dit of dat** and **Vraag**
   (sharing the next row on a phone). **Naam en verjaardag** and **Verwijderen** are in a ⋯ menu
   beside the name.
   *Why (owner's review of a phone screenshot, 2026-09-27):* until then all seven actions were
   equal buttons, four rows of them before anything about the person, with a red "Verwijderen"
   among them and "Stuur hun profiellink" and "Nodig uit op GiftCoves" looking like the same thing.
   Now each kind of action has one place: ideas under the name, reaching them beside their status,
   their taste in "Over", housekeeping in the menu.
2. **Over {naam}**: what you know as chips: interests, style (vibe), what matters to them (values),
   age, budget, what to avoid. **Aanpassen** edits them in place with Find a gift's own
   vocabularies (`GiftController::options()`, the call `GiftProfileCardController` already made,
   plus `GiftTags::VALUE_OPTIONS`, which `options()` does not carry: without it the form crashed on
   opening, found in the same review), saved through the existing `PATCH /recipients/{id}`.
   **Always drawn.** With nothing known it is not an empty box but the page's question: "Je weet
   nog niets over {naam}" with **Vul zelf in** and **Laat {naam} het zelf invullen** (their
   `/for/{token}` link, while no account is behind them). With something known, a small
   "Laat {naam} aanvullen" link under the chips offers the same link until they have answered
   themselves.
   The list rows below carry their kind as a word (`ListKindBadge`: Cadeaulijst, Samen geven), so
   two lists called "Voor David" and "David" can be told apart.
   - **Where it came from.** `taste_source` has two values: `suggested` (you: typed here, in Find a
     gift, or a This or that you played for them) and `self` (they said it through their own link).
     The page says "Ingevuld door jou" or "Ingevuld door {naam} zelf", the detail behind an
     InfoTip. The brief also asked for "guessed from This or that"; that is stored as `suggested`
     like anything else you enter, so the page cannot tell it apart without a new column. Not built.
   - **Their answer wins.** `Recipient::describeTaste()` ignores a guess once they have answered
     themselves. The form therefore does not offer their taste fields in that case (it says why),
     rather than accepting an edit that would silently not be stored. Age and budget stay yours.
3. **Verlanglijsten van {naam}**: their own wish lists (kind `mine`), only while the person is
   linked to an account that is still your friend, and only what My people already shows of theirs
   (`MyPeople::sharedWith()`, made public so the two pages share one query). A list they make for
   somebody else is not their wish list and does not appear here.
4. **Lijsten voor {naam}**: yours, kind `for_someone` or `group`, about this person. Since
   2026-09-27 (owner) always drawn, with **Nieuwe lijst** beside the heading (a menu: Cadeaulijst or
   Samen geven, `POST /lists` with `recipient_id` and `together`). Each row: the name with its kind
   pill right after it (it says what the name is), a **share icon** (the action people come for),
   and a ⋯ menu with **Vraag het aan anderen** and **Instellingen**. Each opens the list page on that tool through
   `?panel=share|settings` (`Lists/Show.tsx` reads it, for someone who may edit the list) or the ask
   form (`/ask?list=`). With no list yet the section says so and offers the button, because starting
   a list for them is what the section is for.

The page opens with **"← Mijn mensen"** above the name, drawn like a list's "← Mijn Coves"
(owner, 2026-09-27).
5. **Samen met {naam}**, for a friend only (below).
6. **Wat je gaf**, the gift history, unchanged ([gift-history.md](gift-history.md)).

List names in these sections are drawn with `ListName` ([list-names-in-text.md](list-names-in-text.md)).

### Samen met {naam}

What you and a friend do together (`App\Services\Social\InCommon`), on the person's page, and as a
count ("samen: N") and a Secret Santa mark on My people. Friends on GiftCoves only: somebody without
an account cannot be matched to a group gift or a Secret Santa, so a saved person with no account
link has no such section at all.

- **Lists they make for somebody else** that reached you (shared with you, or you opened the
  link), with who they are for ("voor Opa"). The recipient's name is on the shared list's own page
  for anybody who may open it, so it is not new here.
- **Group gifts you both take part in**, with their part: "organiseert" or "doet mee". Taking part
  means owning the list (organising), a pledge (`gift_pledges.user_id`) or being a collaborator. A
  group gift you are both in is shown here and not again among their lists.
- **Secret Santa groups you are both in** (as organiser or member, not removed), with the date and a
  link to the group's page on its own market.

**The privacy rules, each with a test in `PeopleTogetherTest`:**

a. **Never a list about you.** A gift list or group gift whose recipient is linked to your account
   (`recipients.user_id` = you) is left out everywhere: their lists on My people, the counts, the
   next date, their page. `Wishlist::scopeNotAbout()` does it, inside `MyPeople::sharedWith()` and
   the group-gift query, so no section can forget it. It applies even when the link reached you,
   because showing it would show you your own surprise. A list about somebody with no account link
   cannot be recognised as being about you, and is not.
b. **A friend's part in a group gift only where the list would name them to you.** The organiser
   decides whether contributors are named (`wishlists.pledgers_visible`, since 2026-09-01). Without
   that, "Sam doet mee" would tell you something the list itself does not, so the group gift then
   shows only when Sam is its organiser, which everybody on it sees anyway. One exception, decided
   without the owner: a group gift **you** organise, whose page already names every contributor to
   you (the organiser's breakdown in `ContributionView`). Collaborators fall under the same rule as
   pledgers. Amounts never appear.
c. **Secret Santa: shared membership only.** Never who drew whom, not even "you drew them". That is
   the owner's default for now; he can opt into showing "you drew them" later, which would be a
   change to `InCommon::santa()` and this paragraph. The pairing column (`assigned_member_id`) is
   encrypted and `$hidden`, and the query never selects it. Members without an account are skipped.
d. **No claim state** (invariant 4): no list's items are loaded, and nothing counts or labels by
   what has been claimed.

### Renaming and deleting

**Naam en verjaardag** edits name, relationship and birthday (day and month, the shared
`DayMonth` picker in `Components/PersonParts.tsx`) through the same `PATCH /recipients/{id}`.

**Verwijderen** opens a confirmation on the page, not `window.confirm`, which says what happens:
lists you made for them stay but no longer say who they are for (`wishlists.recipient_id` is
`ON DELETE SET NULL`); what you gave them and their This or that links go (`recipient_gifts` and
`taste_invites` cascade). After deleting, `then=people` sends you to My people, because "back" is
the page that no longer exists.

**A person with a group gift cannot be deleted**, and the page says so instead of offering the
button. This was a bug before the page existed: a group list must name its recipient (CHECK
`wishlists_group_has_recipient`), so setting it to null made Postgres refuse the delete and the
visitor got a server error. `RecipientController::destroy()` now answers with a sentence
(`people.delete_has_group`) for any caller.

### Merging

`App\Services\Social\MyPeople`. A friend and a saved person are the same row when
`recipients.user_id` is the friend's account id. That link is set when a person is saved "as one
of my friends", by "Bewaar wat je weet" here, when somebody claims their own `/for/{token}` link,
or when an invitation sent from a saved person becomes a connection (since 2026-09-27).
If two saved people point at one friend (saved twice), the friend joins the oldest and the other
stays its own row: nothing the owner wrote disappears. A saved person with status `self` (you,
kept by This or that "for me") is left out.

When a linked row has two birthdays, the one you saved wins, because it is what the reminder email
reads; the friend's own (or your friendship note) fills the gap when you saved none.

### Birthdays in a year without 29 February

`DayAndMonth::nextFrom()` puts a 29 February birthday on the 28th in other years, so the person
does not vanish from the date order for three years in four. Decided without the owner.

## Adding someone

Two buttons at the top, one form open at a time:

- **Iemand toevoegen**: name, relationship (Find a gift's closed vocabulary, stored as its value
  so Find a gift and landing pages read it), optional day and month. `POST /recipients`, the
  same endpoint and validation as everywhere else. No year: the birthday is stored under
  `Recipient::BIRTHDAY_YEAR`.
- **Nodig uit op GiftCoves**: email and optional birthday, `POST /friends`
  ([friends.md](friends.md)). It answers the same whether or not the address has an account.
  Since 2026-09-26 it emails the address (the owner asked for it and for the old "we do not
  email them, so tell them yourself" to go); the (i) says so. The email, its limits and its spam
  link: [friend-invite-mail.md](friend-invite-mail.md).

### Inviting a saved person

Added 2026-09-27 at the owner's request. A saved person with no account behind them
(`recipients.user_id` is null) has **Nodig uit op GiftCoves** in the row's Meer menu and among the
actions on their page. It opens a small form: email, and the birthday, filled in with the one you
saved. It sends **the same invitation** as the button at the top (`POST /friends`,
`FriendController::store`, `FriendInvites::invite()`, the email with its limits and the
no-invitations list), with one more field, `recipient_id`. There is no second path.

**Why the id.** Without it, the invited person would join as a friend row beside the saved one:
Mama twice, with what you know and your lists for her on one row and her own lists on the other.
With it, the saved person is linked to the account when the connection is made, exactly as
"Bewaar wat je over Sam weet" links one: `user_id` set, status `linked`. From then on it is one
row and one page, and what she says about herself through her own link outranks your guesses.

- **An address with an account** is connected at once (as always), and the saved person is linked
  in the same request.
- **An address without one**: the invitation waits in `friend_invites` as always, now with
  `recipient_id`, and `LinkSharerAsFriend` links the saved person when it turns the invitation into
  a friendship at sign-in (since 2026-09-27 usually the one press on the email's accept button,
  which signs in through the same `Login` event; friend-invite-mail.md, "Accepting in one
  press"). The saved person is read fresh then: one that was deleted meanwhile
  (the column is `ON DELETE SET NULL`) leaves an ordinary invitation, and one linked another way
  meanwhile (they claimed their `/for/{token}` link) keeps the account it has.
- Sending the plain form to the same address later does not forget whose invitation it was; naming
  another saved person replaces it (one address, one account, one saved person).
- A birthday typed in the form is kept on the saved person **if they had none**, so it shows on
  their row before they join. One already saved is not replaced: it is what the reminder email
  reads.

**Only your own, unlinked saved person.** The controller looks the id up with your account in the
query: somebody else's id is a 404 before anything is recorded or emailed, and their saved person is
untouched. One of yours that is already linked (a stale page) is ignored and the invitation goes
as from the plain form; the link is never re-pointed. `FriendInvites::mayLink()` holds the rule
(yours, `user_id` null, not your own "self" row), and decides whether the button is offered.

**One account, one saved person (decided without the owner).** If the account is already linked
to another of your saved people (you saved "Sam" from the friend row, then invited Sam's address
from an older "Sammy"), the earlier link stands and the second saved person stays as it was,
unlinked. Merging the two would mean choosing whose interests, lists and history win, and nothing
you wrote should disappear. The database agrees (`recipients_owner_user_idx` is unique on owner and
account); the check comes first so a sign-in never meets that index as an error. Delete the
duplicate yourself if you want one row.

**What the member learns.** The answer is the same sentence, status and redirect whether or not the
address has an account. What the page shows afterwards differs: a saved person linked at once is
"op GiftCoves" at once. That is the disclosure the plain invitation already made (a friend row
appears at once); see [friend-invite-mail.md](friend-invite-mail.md#what-this-does-not-close).
Nothing on the row says an invitation is waiting, for the reason under
[No pending requests](#no-pending-requests).

### No pending requests

The brief asked for incoming and outgoing friend requests with accept and decline at the top of the
page "as the friends page does today". There is no such thing to show: a friendship is made at once
(an address with an account) or when the invited person signs in, never by a request somebody
accepts. Listing the waiting invites would tell the inviter which addresses have no account, which
is exactly what `FriendInvites` exists to hide. So nothing was built for it. If the owner wants
requests, that is a change to how friendships are made, not to this page.

## What stays where

- `/{market}/friends` redirects (301) to `/{market}/people`. Emails, the list help pages and
  bookmarks carry it. It sits outside `auth` now, so a guest following an old link gets the
  explanation rather than the login form.
- `POST /friends`, `PATCH /friends/settings`, `PATCH /friends/{id}` and `DELETE /friends/{id}` are
  unchanged and used by this page.
- "What your friends see of you" (your birthday and whether friends see it) moved to the bottom of
  this page.
- `/people/{id}`, `/for/{token}` and the This or that links are unchanged.
- The Gift Cove tile for friends now links to `/people` directly.

## Privacy

- Saved people are read with `owner_user_id = me`, and nothing else. What a friend saved about you
  never appears, and neither do anybody else's saved people.
- A friend row carries only what the friend shared: their published birthday (or your own note),
  and lists they shared with you or whose link you opened, never a private one, plus the wish
  lists they show to all their people (which may have no link at all; the option is the consent).
- A list a friend is making about you never appears, however it reached you; a friend's part in a
  group gift and Secret Santa membership follow the rules under [Samen met](#samen-met-naam).
- No claim state (invariant 4). No list items are loaded; nothing counts, orders or labels by what
  has been claimed. A list's occasion date is shown, which the list's page already shows anybody
  who may open it.
- Guests get the explanation and a sign-in link, and no data. The page is `noindex`.

## Where it is

| | |
|---|---|
| Route | `GET /{market}/people` (`PeopleController`), `GET /{market}/friends` (`FriendController::index`, a redirect) |
| Merge | `app/Services/Social/MyPeople.php`, `App\Support\DayAndMonth::nextFrom()` |
| Page | `resources/js/Pages/People/Index.tsx` |
| Copy | `site.people.*` (four languages); the friend details reuse `site.friends.*`; `/help` has `people.help` |
| Icons | `ToolIcon` (`cake` for a birthday, `more` for the Meer menu); the menu is `Components/Menu.tsx` |
| Person page | `PersonController::show`, `app/Services/Social/PersonProfile.php`, `resources/js/Components/PersonProfile.tsx` (in `Pages/Recipients/Show.tsx`) |
| Samen met | `app/Services/Social/InCommon.php`, `Wishlist::scopeNotAbout()` |
| Inviting a saved person | `FriendInvites::invite()` / `applyTo()` / `mayLink()`, `friend_invites.recipient_id` (`2026_09_28_000500_an_invitation_can_name_a_saved_person`), `InvitePerson` in `Components/PersonParts.tsx` |
| Tests | `tests/Feature/MyPeopleTest.php`, `PersonProfileTest.php`, `PeopleTogetherTest.php` (the privacy rules), `InviteSavedPersonTest.php`; `FriendsTest` reads the friend rows from `/people` now |

## Not done

- The header and account menu link is another change's work (`/people` is ready for it).
- No editing of a saved person's name, relationship or birthday on the list itself; since
  2026-09-27 that is on the person's page.
