---
name: Saved Coves
area: Coves / Wishlist
status: Active
date_added: 2026-09-26
---

# Saved Coves

A **Cove** is a collection of products on one page: a Daily, a gift-idea page, a buying guide, an
advice article, a brand or shop page. Until 2026-09-26 a reader could share one but not keep it.
Now every published Cove has two buttons:

- **Save** puts a bookmark in *My Coves*, in its **Saved** section (`/lists?view=saved` scrolls there;
  since 2026-09-26 every section of My Coves is on the one page, see
  [list-surfaces.md](list-surfaces.md#one-page-four-sections-2026-09-26)).
- **Make it my list** copies the Cove's products into a new list of the reader's own.

## A bookmark, not a copy

Save stores one row, `saved_coves (user_id, set_id)`, and nothing else. The Cove stays ours: when an
editor changes it, the reader sees the change. That is what a reader expects from "saved" on a page
somebody else keeps up.

Make it my list is the other case, somebody who wants to change the selection. It builds a list
through `ListMaker` and `ItemSaver::saveGroup`, the same path as saving a product by hand, so the
copy is an ordinary list with nothing Cove-specific left in it. It is a **snapshot**: later edits to
the Cove do not reach the list, and removing an item from the list does not touch the Cove. Picks
whose product has no group (no longer in the catalogue) are skipped. Items keep the Cove's order by
giving each a `created_at` a second apart, because lists sort by when an item was added.

The list is made in the **Cove's market**, not the page's, because the products belong to that
market's catalogue (invariant 2: product identity is scoped to the market).

## Needs an account, and a guest is not sent away empty-handed

A cookie-owned bookmark would be lost with the cookie, and the saved view is a signed-in page, so
there are no anonymous rows. A guest who presses either button is handled the way saving a product
is: the press is stored with `/save-intent` (`cove_id`, `cove_action`), the sign-in sheet opens, and
`ReplayPendingSave` finishes the action after sign-in (`PendingSave::replayCove()`). After a copy the
reader lands on the new list.

## What the Saved section shows

Published Coves only, newest save first, each linking in its own market. An unpublished Cove is
hidden, not deleted: the bookmark comes back if the Cove is published again. Unsaving works whatever
state the Cove is in. Saving or copying an unpublished Cove is a 404.

## Community Coves, 2026-09-26

A list somebody published ([community-coves.md](community-coves.md)) can be saved and copied the
same way. `saved_coves` gained a nullable `wishlist_id` beside a now-nullable `set_id`, with a CHECK
that exactly one is set, so the Saved view stays one query over one table. Each saved row now sends
the addresses its Remove and "Make it my list" buttons post to (`saveUrl`, `copyUrl`), because the two
kinds live at different routes. `SaveCove.tsx` takes the same addresses, and falls back to the
editorial Cove's when given only a `coveId`.

## Files

- `database/migrations/2026_09_26_000300_a_cove_can_be_saved.php`, `app/Models/SavedCove.php`
- `app/Services/Cove/SavedCoves.php`: save, unsave, isSaved, the button state, copyToList
- `app/Http/Controllers/SavedCoveController.php`: `POST|DELETE /coves/{id}/save`, `POST /coves/{id}/copy`
- `WishlistController::savedCoves()`, the Saved section in `resources/js/Pages/Lists/Index.tsx`
- `resources/js/Components/SaveCove.tsx`, placed on Daily, Persona, Guide and Entity pages. Since
  2026-09-26 it is the site's one Save button: pressing it opens a panel with "Bewaar in Mijn Coves"
  and "Maak er mijn lijst van" instead of two controls side by side; see
  [save-button.md](save-button.md)
- Test: `tests/Feature/SavedCoveTest.php`
