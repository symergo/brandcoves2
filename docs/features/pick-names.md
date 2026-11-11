---
name: Product names from Cove text
area: Editorial / Catalogue / Frontend
status: Active
date_added: 2026-11-11
---

# Product names from Cove text

**A product without a display title gets the name a Cove's text gives it.** Owner, 2026-11-10: "add a
short product name as generated in the text to the product and show it in the card ... save it to the
database as well when you generate the guide or daily cove", then, 2026-11-11: "switch to
display_title when empty. Use the display title when it exists".

- **Where the name comes from**: the label of the product's link token, `[[product:ID|label]]`, read
  from the body, the editorial, the blurb and each pick's copy; the first usable label wins. The
  writing already names every product it shows, so this costs no extra generation.
- **Usable**: 3 to 80 characters and sharing a real word (three letters or more, not a stopword) with
  the product's feed title or brand. "the odd one" or "deze" read fine in a sentence and are no name
  for a product; the shared word tells them apart.
- **Written to `product_groups.display_title`, only when it is empty.** A title written by hand or
  through the editorial API is never replaced, and neither is the name an earlier Cove gave: the first
  Cove to name a product names it. The update carries `whereNull('display_title')`, so two builds
  racing cannot overwrite each other.
- **When**: `App\Services\Cove\PickNames::fill()`, which `EditionBuilder` runs before storing the
  prose on every build path (Daily, persona/occasion, article, entity Cove), and once over every
  existing Cove in the migration `2026_11_11_000100_display_titles_from_cove_text`.
- **Shown** wherever `ProductGroup::displayTitle()` is read, which is every card on the site (see
  display-titles.md), not only the Cove's own cards.

A per-Cove name (a `daily_picks.short_name` column) was built first and dropped the same night before
it shipped: one name per product, in the existing column, was the owner's choice.

## Wider article column (same day)

The owner also asked to "broaden the article column ... for all the pages with a right column", as on
the guides (2026-11-07, see advice-coves.md). Every two-column page now drops the `max-w-2xl` cap on
its main column and grows the right column 20rem, 26rem from `xl`, 30rem from `2xl`: the Daily Cove,
persona pages, brand and shop Coves (whose rail was 18rem), a list's board (own and shared) and
My taste.
