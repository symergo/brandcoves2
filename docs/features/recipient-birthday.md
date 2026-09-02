---
name: Recipient birthdays
area: Gifting / Notifications
status: Active
date_added: 2026-09-02
---

# A birthday, as a day and a month

`recipients.birthday` was read by [`SendOccasionReminders`](occasion-reminders.md)
every morning and written by nothing anybody could reach. So the column sat empty
on almost every row while the job that needs it ran on schedule — a reminder
feature with no data to remind anybody about.

Two places ask now, and they are not the same person:

| who | where | how well they know |
|---|---|---|
| the giver | creating a list for somebody | guessing — most people cannot name a friend's date |
| the person themselves | `/for/{token}`, the self-describe page | exactly |

The second is the one that matters. The giver is asked because they already have
the person in mind and going back later to add a date is a trip nobody makes; the
recipient is asked because the answer is free.

## Never a year

Every reader matches on **month and day** — a birthday recurs and the stored year
does not, which is why `SendOccasionReminders` extracts both and ignores the
rest. A year is therefore personal data with no use here, and asking for one
invites the arithmetic nobody wants done. Neither form asks; both say so, because
being asked for a birthday and *not* for a year is unusual enough to explain.

## The placeholder year is a leap year, and that is load-bearing

`birthday` is a `date` column, so "14 June" needs *some* year to be storable.
`Recipient::BIRTHDAY_YEAR` is **2000**.

Under 2001 a person born on 29 February cannot be stored at all: Carbon rolls it
to 1 March, silently, and their reminder arrives on the wrong day forever.
`the_twenty_ninth_of_february_is_storable` is the test that keeps it.

A real birth year, where one is supplied by an import, is welcome and
unaffected. This is only the placeholder for the day-and-month form.

## Both halves or neither

`required_with` each way, so a half-filled pair is a visible error rather than a
silently dropped date — somebody who started answering finds out now rather than
months later when no reminder came.

And a pair that passes both ranges can still not be a date: 31 is a valid day and
2 a valid month, and 31 February is not a day. `Recipient::birthdayFrom()`
answers null rather than letting Postgres or Carbon land on 3 March and remind
somebody on a day nobody named.

## Neither form overwrites what it did not ask about

- **Choosing an existing person** when creating a list leaves their details
  alone. The birthday rides along with a *name*, on somebody being minted — a
  blank field quietly overwriting a date entered months ago is an edit nobody
  would ever find.
- **Describing yourself without a date** leaves the stored one standing. Absent
  means "left blank", not "clear it", so answering the taste questions cannot
  wipe a date the giver already knew.

## It is not part of taste

`RecipientProfileController::update()` deliberately writes only taste through
`describeTaste()`, which stamps a `TasteSource`. A birthday is stored beside it
rather than through it: taste is a *characterisation* and this is a *fact*, and
the two are overwritten and reasoned about differently — a date has no source
worth recording.

For the same reason the field is **prefilled** from what is stored, unlike the
taste answers above it, which are deliberately blank until the person has
spoken. Prefilling those with the giver's guesses would reveal what they have
been told about; a date carries no such characterisation and is theirs whoever
typed it.

## Where it is

| | |
|---|---|
| Model | [app/Models/Recipient.php](../../app/Models/Recipient.php) — `BIRTHDAY_YEAR`, `birthdayFrom()` |
| Creating a list | `WishlistController::store`, `ListMaker::make`, `Lists/Index.tsx` |
| Their own page | `RecipientProfileController::update`, `Recipients/SelfDescribe.tsx` |
| Read by | [occasion-reminders.md](occasion-reminders.md) |
| Copy | `site.lists.birthday_*`, `site.recipients.step_birthday`, `site.recipients.birthday_why` |
| Tests | [tests/Feature/RecipientBirthdayTest.php](../../tests/Feature/RecipientBirthdayTest.php) |

## See also

- [occasion-reminders.md](occasion-reminders.md) — the only reader, and why it
  needs month and day rather than a date
- [gifting-lenses.md](gifting-lenses.md) — recipients, and what a giver may store
  about somebody
