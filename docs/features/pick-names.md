---
name: Short product names on Cove cards
area: Editorial / Frontend
status: Active
date_added: 2026-11-11
---

# Short product names on Cove cards

**A card under a Cove's text shows the name the text gives the product, not the shop's title**
(owner, 2026-11-10: "add a short product name as generated in the text to the product and show it in
the card ... save it to the database as well when you generate the guide or daily cove").

- **Where the name comes from**: the label of the product's link token, `[[product:ID|label]]`, read
  from the body, the editorial, the blurb and each pick's copy, first usable label wins. The writing
  already names every product it shows, so this costs no extra generation and always matches the
  paragraph above the card.
- **Usable**: 3 to 80 characters and sharing a real word (three letters or more, not a stopword) with
  the product's title or brand. "the odd one" or "deze" read fine in a sentence and are no name for a
  card; the shared word tells them apart.
- **Stored** on `daily_picks.short_name` by `App\Services\Cove\PickNames::fill()`, which
  `EditionBuilder` runs before storing the prose on every build path (Daily, persona/occasion, article,
  entity Cove). A rebuild after an edit refreshes it; a product the text stops naming goes back to the
  shop title. Existing Coves were filled by the migration that added the column.
- **Shown** through `DailyPick::cardTitle()` (short name, else `ProductGroup::displayTitle()`) on the
  Daily Cove and persona finds (`EditionPresenter`), guide items, brand and shop Coves, the Discover hub
  and the home page rail. Cards outside a Cove (search, lists, scan) keep the catalogue title.

## Wider article column (same day)

The owner also asked to "broaden the article column ... for all the pages with a right column", as on
the guides (2026-11-07, see advice-coves.md). Every two-column page now drops the `max-w-2xl` cap on its
main column and grows the right column 20rem, 26rem from `xl`, 30rem from `2xl`: the Daily Cove,
persona pages, brand and shop Coves (whose rail was 18rem), a list's board (own and shared) and
My taste.
