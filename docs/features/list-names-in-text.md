---
name: List names in text
area: Frontend / Wishlist
status: Active — every list name inside a sentence on the site and in the list e-mails
date_added: 2026-09-26
---

# List names in text

The owner's request (2026-09-26): "When you name a list in text, make clear it is a list name by
styling it consistently as a list name."

Before this, a list's name inside a sentence was plain text in a save confirmation, in quotes in
a notification, and bold in the price digest e-mail. Nothing limits what somebody calls a list, so
"Bewaard in voor mama" could not be read at a glance: where does the name start? The rule for new
code is in [design-system.md](design-system.md#how-to-name-a-list-in-text-2026-09-26-the-standard-from-here-on).

## What it looks like

On the site: **the list kind's line icon, then the name in medium weight**. A heart for your own
wish list, a clipboard for a list about somebody else, two figures for giving together; the plain
`list` icon (three lines) where no kind applies. The icon is the site's accent colour for every
kind, the height of the text around it, and never left alone at the end of a line.

In e-mail: **the name in bold**. Mail clients draw inline SVG unreliably, so no icon there.

## Why the pieces travel separately

A server message used to arrive as a finished sentence ("Bewaard in Camping"), and a finished
sentence has lost where the name is. `App\Support\ListName::mention()` sends four pieces instead:

| piece | what it is | who reads it |
|---|---|---|
| `message` | the finished sentence, as before | tests, screen readers, anything that wants text |
| `template` | the same sentence with `:list` left in | the page, to put the styled name there |
| `name` | the list's name | the page |
| `kind` | `mine`, `for_someone`, `group` or null | the page, for the icon |

`message` is unchanged on purpose: `flash.success`, the JSON `message` and a notification's
`title` still read exactly as before, so nothing that only reads text had to change, and a page
from an older bundle still shows the sentence.

## Quotes

Some translations quote the name ("Nieuw bericht op “:list”"), because in plain text the quotes
were the only marker. Where the name is drawn, `rich()` (in `useTranslations.ts`) and
`ListName::withoutQuotes()` drop a quote pair that wraps the placeholder directly: “ ”, „ ”, « »
with its spaces, ' ', " ". Only a *pair*: a lone quote belongs to some other phrase. The stored
and flashed plain sentences keep their quotes.

## Every place changed

Server (9):

- `WishlistItemController::report()` / `confirm()`: a save's flash and its JSON answer
  (`listKind`, `messageTemplate` added beside `message`).
- `ItemTransferController::between()` and `fromShared()`: "Gekopieerd naar …", including a copy
  from somebody's wish list.
- `SavedCoveController::copy()` and `CommunityCoveController::copy()`: "… staat nu bij je lijsten".
- `ReplayPendingSave` with `PendingSave::replayFor()`: the save finished at sign-in, which now also
  returns the list's kind (null for a Cove that was only bookmarked).
- `HandleInertiaRequests`: shares `success_list` as `flash.list`.
- `ListActivity`: every list notification stores `payload.list` (template, name, kind).
- `SendListPriceDigests`: the digest notification's title is the list's name, stored the same way.
- `NotificationController`: passes `payload.list` to the page.
- `AddingMode::current()`, `GiftResults::recipientList()`, `AskController::askersList()`,
  `AskPrefill`, `PersonController::theirList()`: the list references pages use in sentences now
  carry `kind`.

Site (13):

- `FlashMessage`: draws `flash.list` when it belongs to the message on screen.
- `SaveToast` (with `saveToast.ts` `listFrom()`): "Bewaard in ♡ Camping". On a phone the sentence
  now takes its own line above Undo / View list, because beside them it was cut to "Bewaard in …",
  losing the name, which is the one part that is news.
- `SaveToList`: the Bewaar sheet's "Eén druk op Bewaar zet het in …" hint, and its toasts.
- `OfflineIdeas`: its save toast.
- `AddingToBar`: "Toevoegen aan …".
- `GiftResults`: "Wat je bewaart, komt op …".
- `Ask/Index`: "Voor je lijst …"; `Ask/Show`: "Ideeën die je uit de antwoorden bewaart, komen op …".
- `TheirWishes`: the links to each of somebody's wish lists, under "Uit de verlanglijst van …",
  when there is more than one.
- `Notifications`: every list notification and the price digest row.
- `Lists/Index`: the My Coves section headings carry the kind icons (not a list name, but the same
  icons, so a kind looks the same everywhere it has one).
- `ToolIcon`: the heart redrawn at nine tenths of its size around the centre (it ran edge to edge
  of the grid while the clipboard and the two figures keep a margin, and read a size larger next
  to them); a new `list` icon for a list with no kind.

E-mail (2):

- `mail/list-invitation`: "… op een lijst met de naam **Camping**", unquoted.
- `ListInvitationMail` (with `UsesTemplate::template()`'s new `$bodyValues`): an editor's own
  version of that mail gets the bold name in the body and the plain name in the subject.

## Left plain on purpose

- **Attributes that can only hold a string**: the save button's `title`/`aria-label` ("Bewaar in
  …"), a picker row's tooltip, and texts sent out of the site (the share text, "Ideeën voor …?"
  message). An element cannot go there.
- **A list's own title where it is the subject**: page headings, cards, picker rows, the
  `<select>` options on the Secret Santa pages, and the list chips on My people. These already
  read as a list by where they stand.
- **"Uit de verlanglijst van Anna"** names a person, not a list; the list names under it are
  styled.
- **The price digest e-mail**: each section already opens with the list's name, bold and linked.
- **Occasion reminders**: they name the occasion ("Jouw verjaardag komt eraan"); only a list with
  no occasion type falls back to its title there, and that sentence is about a date.
- **Old notifications**: rows written before this carry no `payload.list` and show their title as
  it was, quotes included.
- **The admin**: out of scope.

## Tests

`tests/Feature/ListNameTest.php`: the flash in both shapes, the JSON answer, `flash.list` on the
next page, a copy's flash, a notification's payload, the quote rule, and the e-mail's bold and
escaping.
