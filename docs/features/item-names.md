# A name of your own for an item on a list

Owner, 2026-11-07: "allow everywhere to change the name of an item on a list".

## What it does

Every item on your own list can be renamed from its ⋯ menu. A hand-written item keeps the form it
always had ("Aanpassen": name, link, price, photo, `EditManualItem`). A product from the catalogue
gets "Naam wijzigen", one field under the card (`RenameItem`), with "Terug naar de naam van de
winkel" once it has a name of your own.

The name is shown wherever the item is: the list page, the shared list, Secret Santa, the price
emails, gift history and next steps. All of those read `WishlistItem::displayTitle()`, which now
reads `custom_title` first, then the product's name, then the saved name.

## Why a column of its own

On a catalogue item `snapshot_title` and `snapshot_price` are a record of what the shop said when
the product was saved; "you saved it at €329, it is €279 now" is measured against them, and the
update action has always refused to let anybody rewrite them (`WishlistItemController::update`).
So the name goes into `wishlist_items.custom_title` (migration `2026_11_07_000200`, nullable,
additive), and the record stays as it was. Blank, or exactly the product's own name, clears it,
so "going back" leaves nothing behind.

## Where it is not used

- **Published Coves** (`CommunityCoves`) show the product's own name. A public page is read by
  search engines and strangers; "voor mama's verjaardag" is a note to yourself, not a title.
- **Claim and activity messages** keep the snapshot, as they already did: they record what was said
  at the time.
- **Somebody else's list.** Only the owner has the ⋯ menu; a co-editor's card carries the save
  button. The server allows anybody who may edit the list (`ListAccess::canEdit`), as for notes.

`ManualItemTest::a_catalogue_item_keeps_the_snapshot_the_feed_gave_it` covers both halves: the
record untouched, the name yours, and both ways back.
