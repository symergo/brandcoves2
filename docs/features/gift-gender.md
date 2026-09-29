---
name: For him or for her
area: Gifting
status: Active
date_added: 2026-09-29
---

# For him or for her

**Whether a gift is for him or for her is its own, optional question: "Voor hem / Voor haar".**
The relations stay as they were (partner, mama, papa, oma of opa, zoon of dochter, vriend of
vriendin, collega, broer of zus, leerkracht, gastheer of gastvrouw).

## Why not split the relations

On 2026-09-29 the relations were split by gender for a day (oma and opa, zoon and dochter, … as
separate values) and then reverted, on the owner's word: "Maybe the gender split was not a good
idea. Revert it and ask for gender instead. Products then only get a gender if relevant and there
is no duplication." Splitting the relations doubled the tags: most products suit an oma and an opa
alike, so they needed both. A separate gender needs a tag only where it matters.

## How it works

- **The question**: two small switches next to "Voor …" above the ways in Find a gift. Optional;
  not shown for yourself, nor for mama or papa, whose relation already says it
  (`RecipientType::impliedGender()`).
- **Remembered** on a saved person (`recipients.gender`, nullable, CHECK male/female), like the age,
  and filled in from them next time.
- **Carried** to Swipe gifts and This or that (`?gender=`, `CarriedWho`), so their cards leave out
  the same products (`DeckSeed::allows`).
- **The rule**: a product tagged `gender:` for the other one is left out
  (`SuggestionEngine`, next to the avoided interests). A product with no gender tag suits both and
  is never left out; that is nearly every product. Not a score, a filter: a razor is not a slightly
  worse gift for a woman, it is not the gift.
- `TasteBrief::gender()` answers "him, her or either": what the giver said, else what the relation
  says.

## Tagging

`gender:male` / `gender:female` is a tag vocabulary (`GiftTags::GENDER`) the tag API accepts. The
tagging brief says: only when the product is genuinely for one (a men's razor, a dress, a "best
grandma" mug), never both, and most products get none.

## Undoing the split (migration `2026_09_29_000300_relations_merged_gender_asked`)

The split's migration had given every product tagged for a combined relation both gendered tags.
This one put them back: both of a pair became the combined tag again; one of a pair alone became the
combined tag plus that one's gender (none on production, since tagging was paused). The gendered
landing pages and crowd counts went, the relation Coves got their relationship back by slug, and the
"done" idea announcing the split left the Denk mee board. `tests/Feature/RelationsMergedMigrationTest.php`.

## Files

- `app/Enums/Gender.php`, `app/Enums/RecipientType.php` (`impliedGender()`)
- `app/Services/Gift/TasteBrief.php`, `SuggestionEngine.php`, `DeckSeed.php`, `DeckSeeds.php`,
  `CarriedWho.php`, `GiftTags.php`
- `app/Http/Controllers/GiftController.php`, `TasteController.php`, `SwipeController.php`
- `resources/js/Pages/Gift/Wizard.tsx`, `Taste.tsx`, `Swipe.tsx`
- `tests/Feature/GiftGenderTest.php`
