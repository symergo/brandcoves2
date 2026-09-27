# One Save button

**Status:** Active since 2026-09-26 (UX audit, item 8).

Keeping something on GiftCoves is one button with one word and one icon: **Bewaar** (en Save, fr
Enregistrer, es Guardar), with a bookmark icon. It is outlined before and filled after, when it reads
**Bewaard** (Saved, Enregistré, Guardado). The same button is on a product card, on a product page
and on a Cove, and pressing it opens the same small panel (the *sheet*): a popover under the button
on a computer, a panel sliding up from the bottom on a phone.

## Why

Before this, "saving" was four different controls:

| Where | What it was |
|---|---|
| Product card | A round bookmark with a narrow "▾" stuck to it (desktop only). At card size the "▾" read as a minus sign. |
| Product page, Cove product rows | A "Bewaren" button with a separate "▾" button beside it. |
| Cove page | A "Bewaren" button (own style, solid green once saved) plus a "Maak er mijn lijst van" link. |
| This or that | "Bewaar voor [naam]" buttons in a box of their own. |

Four shapes for one idea meant nobody could learn it once. The audit asked for one control.

## What pressing it does

### On a product

- **Not saved yet: one press saves.** It goes to the list the button's label names ("Bewaar in
  Camping"): the list the page chose (`into`, e.g. Find a gift's person), else the list being
  filled in adding mode, else the list used last on this device, else the default list ("Mijn
  verlanglijst"). The toast names the list and offers Undo and "Bekijk lijst". This is unchanged
  from before.
- **Saved: one press opens the sheet.** The sheet ticks every list the product is on, with the list a
  single press uses at the top of its section. Tick another list to add it there too, untick one to
  take it off. "+ Nieuwe lijst", "+ Iemand toevoegen" and "+ Samen een cadeau kopen" start a new
  list with the product already on it. A line at the top says what the ticks mean.
- Guests: the press stashes the save (`POST /save-intent`, `App\Services\Wishlist\PendingSave`) and
  opens the sign-in dialog; the save finishes after sign-in. Unchanged.

**Decision (taken without the owner): the first press still saves.** The audit allowed either. We
kept one-press saving because the whole product-card design rests on it (the remembered last list,
adding mode, Find a gift's `into`, the toast with Undo), and because a sheet on every press
would double the taps for the common case of "keep this". The cost is that choosing a *different*
list before saving now takes two presses (save, then press again to open the sheet) where the
desktop chevron took one. We judged that acceptable: the sheet is always exactly one press away, it
opens with the product ticked on the list it just went to, and ticking a second list keeps the first,
so nothing is lost. For filing a whole grid into one named list, adding mode (started from a list's
own page, it points every save at that list) is the fast path, and it is already there.

The chevron is gone everywhere. `SaveToList` keeps its props (`groupId`, `compact`, `into`, and the
live-offer fields), so every page that places it compiles unchanged.

### On a Cove

- **Every press opens the sheet**, with two choices and one line on what each means:
  - **Bewaar in Mijn Coves**: a bookmark. The Cove stays ours (or its maker's, for a Community
    Cove) and changes when it is updated. Found under My Coves, Saved Coves.
  - **Maak er mijn lijst van**: copies its products into a new list of your own.
- **Saved: the button reads Bewaard**, and the sheet says "Bewaard in Mijn Coves" with a link there
  and "Haal uit Mijn Coves", above the copy choice.
- Guests: either choice stashes the matching intent and opens sign-in, as before.

**Why a Cove does not save on the first press** when a product does: on a Cove "save" can mean two
different things, and guessing wrong costs either the list you wanted or a copy you did not. A
product has only one meaning (put it on a list), so guessing is cheap and the toast says where it
went.

The "Open Mijn Coves" link goes to `/{market}/lists`, not to the `?view=saved` tab, because My Coves
is being reorganised into one page in parallel and the plain address survives that.

### This or that ("Bewaar voor [naam]")

Left alone on purpose. It saves a *taste* (answers about a person) rather than a thing, there is no
saved state to show on the button afterwards (the box is replaced by a sentence), and one button per
person is the choice itself. It already follows the wording pattern (the verb Bewaar, then who for),
so it stays recognisably the same act without borrowing the bookmark, which would promise a filled
state it cannot show. Since 2026-09-27 the buttons are people cards (`PersonPicker`), friends
included; see [taste-discovery.md](taste-discovery.md).

### The sheet's rows are `ListPicker` (2026-09-27)

The sheet's body (the hint line, three sections, a tick box per list, "+ Maak een Cove", and the
form that names a new list) is `ListPicker` since the consistency review's round 3, shared with the
two copy menus ([copying-items.md](copying-items.md)). Each row now carries its kind's icon before
the name, as a list's name does in a sentence (`ListName`), and naming a new list has a Cancel back
to the rows. What a tick does is unchanged and stays in `SaveToList`.

## How it is built

- `resources/js/Components/SaveButton.tsx`: how the button looks. `compact` is the round icon-only
  one for a product photo; otherwise icon plus word. Also exports `BookmarkIcon`.
- `resources/js/Components/SaveSheet.tsx`: the panel. A portal into `document.body` (a product card is
  `overflow-hidden` and would clip it), a popover that flips above the button near the bottom of the
  screen and scrolls when long, a bottom sheet under 640px. Escape or a press outside closes it;
  opening puts focus on the first control, Escape puts it back on the button.
- `resources/js/Components/SaveToList.tsx`: the product logic (unchanged: optimistic tick, the
  last-list fallback on a 404/403, holders from `savedItems.ts`, including a saved product's twin in
  this market, which the server reports). Rows are `role="checkbox"` inside a `role="dialog"`.
- `resources/js/Components/SaveCove.tsx`: the Cove logic (unchanged routes: `POST|DELETE
  /coves/{id}/save`, `POST /coves/{id}/copy`, or a Community Cove's own addresses).
- Strings: `site.save_button.*` in all four languages; the old `lists.save_to`, `lists.saved` and
  `saved_coves.*` keys are still used where they were.
- Help: `/lists-help` (the bookmark and "save" pages) and `/help` (`coves_body4`) describe the one
  button; the "small arrow on a computer" sentences are gone.

No server change: the endpoints, `PendingSave` and the saved-items feed are as they were, and the
existing tests cover them (`SaveToListTest`, `SavedCoveTest`, `CommunityCoveTest`, `PendingSaveTest`,
`SavedItemsTest`, `WishlistTest`).
