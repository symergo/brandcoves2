---
name: Man or woman
area: Gifting
status: Active
date_added: 2026-09-29
---

# Man or woman

**Whether the person is a man or a woman is its own, optional profile question: "Man / Vrouw", beside the
age.**
The relations stay as they were (partner, mama, papa, oma of opa, zoon of dochter, vriend of
vriendin, collega, broer of zus, leerkracht, gastheer of gastvrouw).

## Why not split the relations

On 2026-09-29 the relations were split by gender for a day (oma and opa, zoon and dochter, … as
separate values) and then reverted, on the owner's word: "Maybe the gender split was not a good
idea. Revert it and ask for gender instead. Products then only get a gender if relevant and there
is no duplication." Splitting the relations doubled the tags: most products suit an oma and an opa
alike, so they needed both. A separate gender needs a tag only where it matters.

## How it works

- **Where it is asked** (owner: "a separate optional profile question: male / female", with the
  age): in Find a gift's age step ("Man of vrouw?", for yourself "Ben je een man of een vrouw?"),
  on a person's page next to the age, and in My taste. Optional everywhere; not asked for mama or
  papa, whose relation already says it (`RecipientType::impliedGender()`). A first version had two
  "Voor hem / Voor haar" switches at the top of Find a gift; the owner moved it to the profile.
- **Three answers**: Man, Vrouw, and "Zeg ik liever niet" (owner, the same day). The third is
  remembered, so the question doesn't come back as if it were open, and behaves exactly like no
  answer: nothing is left out, and a relation implies nothing over it. It is never a product tag
  (`Gender::tagValues()` is male and female only).
- **Kept** on a saved person (`recipients.gender`, a giver's fact like the age, so not on the
  self-describe link) and on My taste (`user_tastes.gender`, which a friend's search reads like the
  rest of your taste), nullable, CHECK male/female.
- **Carried** to Swipe gifts and This or that from the saved person or your own taste (and
  `?gender=` when a link carries it), so their cards leave out the same products
  (`DeckSeed::allows`).
- **The rule**: a product tagged `gender:` for the other one is left out
  (`SuggestionEngine`, next to the avoided interests). A product with no gender tag suits both and
  is never left out; that is nearly every product. Not a score, a filter: a razor is not a slightly
  worse gift for a woman, it is not the gift.
- `TasteBrief::gender()` answers "him, her or either": what the giver said, else what the relation
  says.

## Tagging

Part of product tagging (owner: "this should be part of product tagging"). `gender:male` /
`gender:female` is a tag vocabulary (`GiftTags::GENDER`) the tag API accepts, and the admin's
Product tagging instruction and the tagging skill ask for it. The tagging brief says: only when the product is genuinely for one (a men's razor, a dress, a "best
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
