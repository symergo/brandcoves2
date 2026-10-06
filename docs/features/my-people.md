---
name: My people
area: Accounts / Gifting
status: Active
date_added: 2026-09-26
---

# My people ("Mijn mensen")

> **Since 2026-09-29** there is no vibe (Handig / Leuk / Mooi) and no values (sustainable / local /
> handmade) anywhere; taste is the pairs of opposites only. See [taste-pairs.md](taste-pairs.md).
> Mentions of either below are history.

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
on `/lists`. Who you buy for and what you collect are the two halves of the same job. The header has the
same structure as `/lists` too: title left; right, "Mijn Coves" first, then this page's own actions,
**Iemand toevoegen** (filled) and **Nodig uit op GiftCoves**. Those two sat in a row of their own
under the intro until the owner moved them up the same day. Same design on both pages since then: "Maak een
Cove" (`NewListButton`) became the filled `Button` that "Iemand toevoegen" is, outlined while its
form is open, each with a line icon (+, and the invite figure for "Nodig uit"), and both titles
are the same size.

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
  a friend is on this page for. What is about the connection is in the row's ⋯ since 2026-09-27
  (it was a separate **Details** toggle, a second "more" beside the ⋯): **Verjaardag
  toevoegen/wijzigen** (your note of their birthday, opened under the row), **Jouw lijsten die
  {naam} ziet (N)** (opened under the row), and, below a rule in red, **Verwijder als vriend**,
  which asks first in the site's own popup (`ConfirmDialog`), not `window.confirm`.
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

1. **The person**: their name as the heading, with an **op GiftCoves** pill beside it for a
   friend or any saved person with an account behind them (until 2026-10-05: friends only, on the
   line under the name). Under the name the relationship, and for somebody without an account
   **"Niet op GiftCoves · Nodig uit"** (see [Inviting a saved person](#inviting-a-saved-person)).
   Everything else is in the ⋯ menu beside the name: **Cadeau vinden** (the wizard with them
   chosen), **Dit of dat**, **Vraag**, then **Over {naam}**, **Naam en verjaardag**, **Verwijder
   als vriend** (a friend only, asking first; the saved person stays) and **Verwijderen**.
   *Why:* the owner's review of 2026-09-27 found seven equal buttons, four rows of them before
   anything about the person. On 2026-10-05 the owner moved the three ways to an idea into the menu
   too, then "Over" itself, so the page opens on their lists.
2. **Over {naam}**, a popup from the menu (a section on the page until 2026-10-05). What you know
   as chips: the birthday first (moved here from under the name; unknown, it is the way to add it),
   interests, the taste pairs (Handig|Design and the rest; `about.preferences`), age, man or woman,
   what to avoid. No budget: that is a list's since 2026-10-05 ([list-budget.md](list-budget.md)).
   For a friend with their own **Mijn smaak** the popup shows that taste laid over your notes,
   exactly as every search for them reads it (`OwnTaste::overlay`, `tasteSource: account`), with
   "Ingevuld door {naam} zelf" and an (i) saying so. Until 2026-10-06 it showed only your notes, so
   such a friend looked empty here while the search used their answer (owner: "contains no
   tastes"). Full screen on a phone, labels above their chips, like every popup
   ([design-system.md](design-system.md)).
   Under the chips, **Aanpassen** edits them in place with Find a gift's own vocabularies
   (`GiftController::options()`; the taste pairs too since 2026-10-06, owner: "I don't see the
   vibes when editing"), saved through `PATCH /recipients/{id}`. The form sits straight in the
   popup like its other forms, with Opslaan in a bar along the foot, and **Vraag {naam} om
   aan te passen** opens their `/for/{token}` link to send. That link is offered always, also once
   they answered themselves or have an account: it was hidden in both cases until 2026-10-05, and
   the owner found it missing. The link page already handles a linked person. With nothing known
   the popup asks the question instead: "Je weet nog niets over {naam}" with **Vul zelf in**,
   **Verjaardag toevoegen** and **Laat {naam} het zelf invullen**.
   The list rows below carry their kind as a word (`ListKindBadge`: Cadeaulijst, Samen geven), so
   two lists called "Voor David" and "David" can be told apart.
   - **Where it came from.** `taste_source` has two values: `suggested` (you: typed here, in Find a
     gift, or a This or that you played for them) and `self` (they said it through their own link).
     The page says "Ingevuld door jou" or "Ingevuld door {naam} zelf", the detail behind an
     InfoTip. The brief also asked for "guessed from This or that"; that is stored as `suggested`
     like anything else you enter, so the page cannot tell it apart without a new column. Not built.
   - **Their answer wins.** `Recipient::describeTaste()` ignores a guess once they have answered
     themselves. The form therefore does not offer their taste fields in that case (it says why),
     rather than accepting an edit that would silently not be stored. Their age stays yours.
3. **Verlanglijsten van {naam}**: their own wish lists (kind `mine`), only while the person is
   linked to an account that is still your friend, and only what My people already shows of theirs
   (`MyPeople::sharedWith()`, made public so the two pages share one query). A list they make for
   somebody else is not their wish list and does not appear here.
4. **Lijsten voor {naam}**: yours, kind `for_someone` or `group`, about this person. Since
   2026-09-27 (owner) always drawn, with **Nieuwe lijst** beside the heading (a menu: Cadeaulijst or
   Samen geven, `POST /lists` with `recipient_id` and `together`). Since 2026-09-27 (consistency
   review, round 2) each row **is Mijn Coves' row** (`ListSummaryRow`): the pictures, the count and
   occasion, the pills, and **Toevoegen**, **Delen** (the same share popup) and the same ⋯ per kind
   (`listActionItems`: suggestions from {naam}, Vraag het aan anderen, Instellingen, and Verwijderen
   in red with a confirmation). Until then this page drew the name, a kind pill, a share icon and
   a ⋯ with two of those items, so the same list offered different things depending on which page
   you met it on. "Wat je {naam} gaf" is left out here, being this page. `PersonProfile::
   listsForThem()` sends the row's shape (`summarise()`'s fields plus covers). Deleting from a row
   sends `stay`, and `WishlistController::destroy()` then returns to this page rather than to Mijn
   Coves. With no list yet the section says so and offers the button, because starting a list for
   them is what the section is for. **Their wish lists** use the same row without actions.

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

**Verwijderen** opens a confirmation popup (`Modal`, since 2026-09-27; it was a red box under the
header), not `window.confirm`, which says what happens:
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
  this page. Since 2026-09-27 it asks **day and month** with the same two lists (`DayMonth`) as every
  other birthday on the site; it was the one place that asked a full date with a year, and friends
  only ever saw the day and month of it. `PATCH /friends/settings` takes `MM-DD` and stores it under
  `Recipient::BIRTHDAY_YEAR`, refuses a day the month does not have, and keeps a year somebody gave
  before while the day and month are unchanged, so saving the switch never rewrites a date. The page
  gets `settings.birthday` as `MM-DD` (`FriendsTest::your_own_birthday_is_a_day_and_a_month_too`).
- The row's "Cadeau vinden" is outlined since 2026-09-27 (`rowActionClasses()`): the header's
  "Iemand toevoegen" is the page's one filled button. On the person's own page it stays filled,
  because there it is that page's one primary action. "Op GiftCoves" is a `Badge`, and the "Wat
  zij zien" chips under Details are `ListName`s like their lists above them.
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
