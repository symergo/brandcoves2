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

## What a row shows

- the name (your own name for them when you saved them), and the relationship in the reader's
  language when it is one of the closed vocabulary (`mother` shows as "Mama"); a relationship typed
  by hand shows as typed, and one that equals the name is not repeated;
- the next date: the nearest of their birthday and the occasion date on a list about them, with
  "vandaag", "morgen" or "over N dagen";
- for a saved person: **Cadeau vinden** (`/gift?for=<id>`, Find a gift straight on their ideas),
  **Hun pagina** (`/people/{id}`; on a phone the name is the link, to keep the buttons on one line),
  **Dit of dat** (`/gift/taste?person=<id>`), **Vraag** (`/ask?person=<id>`: Ask others, the
  form opened and filled in with their relationship, interests, style and budget, never a name or
  a note; added 2026-09-27 at the owner's request), and **Dit of dat samen** only while a This-or-that-
  together link is open for them (it goes to the list about them, where that panel lives);
- for a friend: **Hun lijsten (N)** or **Details**, which opens the old friends-page detail: their
  lists, which of yours they see, your birthday note about them, and removing the connection.
  Since 2026-09-26 "their lists" includes every wish list they made "visible to my people", and
  "which of yours they see" every one of yours
  ([wish-list-for-my-people.md](wish-list-for-my-people.md));
- for a friend nobody saved yet: **Bewaar wat je over [naam] weet**, which creates a saved person
  linked to their account through the existing `POST /recipients` with `friend_id`
  (`RecipientController::store`, which checks the friendship). From then on the row has everything
  a saved person has.

Sorted by the next date, then by name (accents folded). People without a date come last.

### Merging

`App\Services\Social\MyPeople`. A friend and a saved person are the same row when
`recipients.user_id` is the friend's account id. That link is set when a person is saved "as one
of my friends", by "Bewaar wat je weet" here, or when somebody claims their own `/for/{token}` link.
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
| Tests | `tests/Feature/MyPeopleTest.php`; `FriendsTest` reads the friend rows from `/people` now |

## Not done

- The header and account menu link is another change's work (`/people` is ready for it).
- No editing of a saved person's name, relationship or birthday on this page; that stays where it
  was.
