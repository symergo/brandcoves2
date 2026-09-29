---
name: Relations split by gender
area: Gifting
status: Active
date_added: 2026-09-29
---

# Relations split by gender

**Oma and opa, zoon and dochter, broer and zus, vriend and vriendin, juf and meester, gastheer and
gastvrouw are separate relations since 2026-09-29, and the combined ones are gone.** The owner:
"in the relations: split the gender versions", then the full split (own chips, own typical
interests, own tags, own pages) with Juf and Meester too, and then "just delete all the combined
gender tags to keep simpler code". Partner, mama, papa and collega stay as they were.

Until then `RecipientType` said the opposite on purpose ("relationships, not genders"), on the
grounds that a product tagged "for women" is more often a stereotype than a fact. The split answers
a different point: nobody thinks of their oma as "oma of opa", and that was the chip.

## Sixteen relations, one value each

partner, mother, father, grandmother, grandfather, son, daughter, brother, sister, male_friend,
female_friend, colleague, female_teacher, male_teacher, male_host, female_host. Matching is what it
always was, one value against one tag. An intermediate design kept the six combined values as an
"either" with a family rule in eight places; the owner preferred the simpler code.

**A product that suits both of a pair carries both tags.** That is how the migration
(`2026_09_29_000200_relations_split_by_gender`) kept what was known: every product tagged
`recipient:grandparent` (25,725 on production, most of the catalogue tagging so far) now has
`recipient:grandmother` and `recipient:grandfather`, and likewise for the other five. The tagging
brief tells a tagger to tag both unless a product is clearly for one.

The migration also removed what can't be split:

- **Relation pages for a combined relation** (878 on production, `/gift-ideas/for/oma-of-opa`, …):
  the planner builds the gendered ones (`oma`, `opa`, `zoon`, …). The old URLs now 404; there is no
  one page to send them to.
- **The relation field of the 12 relation Cove plans** ("voor oma en opa", "voor een kind", …): the
  Coves stay, about both, and a brief without a relationship reads as "anybody".
- **Crowd counts** for a combined relation, which the nightly count makes again.

Saved people, votes, searches and ideas without a shop held no combined value on production.

## Typical interests

Each relation has its own `gift_landings.hub_interests_by_recipient` list, so oma and opa start
from different interests on their pages and in the choosing games' seed. What was ruled out for a
child (drinks, coffee, hunting) is ruled out for a son and a daughter (`excluded_pairs`).

## Words people type

`lang/*/intent.php` maps what people type onto the values: "oma", "grootmoeder" to grandmother,
"juf" to female_teacher. Words that name both ("grootouders", "kind", "leerkracht", and in English
"friend") match no relation now, which the site already treats as "anybody".

## Files

- `app/Enums/RecipientType.php`
- `database/migrations/2026_09_29_000200_relations_split_by_gender.php`,
  `tests/Feature/RelationsSplitMigrationTest.php`
- `config/giftcoves.php` — `gift_landings.hub_interests_by_recipient`, `excluded_pairs`
- `lang/*/site.php` (`gift.relationships`, `community.for`, `ask.prefill_who`,
  `gift_landing.recipients`), `lang/*/intent.php`
- `.claude/skills/giftcoves-tag-products/reference/brief.md`
