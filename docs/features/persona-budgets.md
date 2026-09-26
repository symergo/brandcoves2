---
name: Budget tabs on a persona
area: Gifting / Content
status: Active — every published persona with something to go on
date_added: 2026-09-26
---

# Budget tabs on a persona: around 15, 40 and 100

The owner's request 7 (2026-09-26). A persona page ([gift-personas.md](gift-personas.md)) is one
editor's shelf at whatever prices it came to. Under it, three tabs answer "and if I mean to spend
about this much?": **around €15, around €40, around €100**. Each is the suggestion engine's answer
to the persona's brief within that band, so the page helps whatever the budget.

The curated editorial and products are untouched and come first. The tabs are additional, and they
never repeat a product already on the shelf.

## The price points (`config/giftcoves.php`, `persona_budgets.bands`)

| Tab | Searches |
|---|---|
| around 15 | 5 to 25 |
| around 40 | 25 to 60 |
| around 100 | 60 to 150 |

15, 40 and 100 are the three amounts people name for a present: a small something (a colleague, a
host), a proper present (a friend, a sibling) and a big one (a partner, a parent, a round
birthday). The bands meet with no gap so no price falls through, and each reaches above its point,
because "around 40" that stops at 40 is "up to 40". The help page names the three amounts, so a
change here is a change to four help lines too.

In cents, like every price (invariant 7). The currency is the market's, which is the euro
everywhere today; the tab label is formatted per market on the client ("€ 15" in Dutch, "€15" in
English), without cents, because a tab names a budget, not a price.

## Filling a tab

`App\Services\Gift\PersonaBudgets`:

1. **The brief.** In order of how much a person decided it: the plan's own gift brief
   (`cove_plans.brief`); the drawing (a persona drawn with a coffee cup is about coffee;
   `racing`, `dog` and `plants` map to gaming, pets and gardening; `has_everything` is the
   has-everything brief); the interest tags on its products; and last, the interests their titles
   point at (`InterestGuesser`). Two products must agree on a tag or a word, or it is one product's
   accident. When none of that says anything, **no tabs**: a budget browse wearing the persona's
   name would be worse than nothing.
2. **The engine, per band**: the brief with that budget, six products, the shelf's own products
   excluded.
3. **Only what fits**: products the engine says answer the interest, or that get used up or done
   for a has-everything persona ([has-everything.md](has-everything.md)). The engine's fallback
   (a budget browse when nothing matches) never reaches a tab. The price is checked again against
   the band, because a tab that says "around 15" and shows 40 breaks its promise.
4. **Three or more, or the tab is left out.** No tab reaches three, no section.

## Cost

Retrieval and arithmetic, no AI (the page is a web request, invariant 1). Cached a day per persona,
keyed on the edition's and the plan's last change, so three engine runs per persona per day rather
than per visitor, and a rebuilt persona never waits for the cache.

## Ideas without a shop

The same brief also asks `OfflineIdeaPicker` for approved offline ideas
([offline-ideas.md](offline-ideas.md)), shown under the tabs with the Gift Finder's block. For most
personas that is ideas sharing the interest; for someone who has everything it is the ideas that are
done or used up (a workshop, a tasting).

## Files

- `app/Services/Gift/PersonaBudgets.php`, `app/Http/Controllers/GiftIdeasController.php`
- `resources/js/Components/PersonaBudgets.tsx`, `resources/js/Pages/GiftIdeas/Persona.tsx`
- `config/giftcoves.php` (`persona_budgets`), `lang/*/site.php` (`gift_ideas.budget*`,
  `help.find_personas`)
- `tests/Feature/PersonaBudgetsTest.php`
