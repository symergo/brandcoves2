---
name: Top 10 of the week on a persona
area: Gifting / Content
status: Active — every published persona that can fill six places; refreshed Mondays
date_added: 2026-09-27
---

# Top 10 of the week on a persona

The owner's request (2026-09-27): every persona Cove ends in an automatic top 10, updated weekly.
That covers the existing interest personas ("De thuiskok", "De wandelaar") and the relation and
occasion personas the owner asked for alongside it. It is the last section of the persona page,
under the budget tabs ([persona-budgets.md](persona-budgets.md)) and the ideas without a shop.

## What "top" means here

The site records no clicks to shops, so "top" cannot mean "most clicked". It is built from what
exists:

- **The pool** is the suggestion engine's answer to the persona's brief, the same pool as the
  budget tabs and filtered by the same check (`PersonaBudgets::fits()`), so the two sections agree
  on what belongs to a persona. The curated shelf's own products are left out: the list is more
  ideas, not the shelf again.
- **The order**: a bestseller-chart position (rank 1 is worth 100 points, rank 100 is worth 1),
  plus 25 points per distinct wish list on this site that holds the product. Four lists weigh as
  much as a number 1 on a retailer's chart: our own visitors' wishes are the rarer and more honest
  signal. Where neither says anything, the engine's own order decides.
- **At most two products per brand**, so a coffee persona is not ten machines from whoever has
  the most listings.
- **Tagged before title-only** (2026-09-27). A product whose interest tag (an editor's, or enough
  people's lists) matches the persona goes before one that only has one of the interest's search
  words in its title. The first list for "De thuiskok" held an audio mixer and a DJ controller,
  matched through the word "mixer". Title-only products still fill in where too few are tagged:
  be-nl has about 54,000 tagged products, nl-nl about 700.
- **Spread over four price bands** (under €25, €25–75, €75–150, over €150; 2026-09-27). The engine
  is asked once per band and the ten places are taken from the bands in turn, then numbered by the
  ranking above. Without it, the engine's own order decided (few products carry a chart or wish-list
  signal yet) and "De thuiskok" got ten appliances between €277 and €479.

### What the ranking cannot fix

The list trusts the catalogue's interest tags and its giftability verdict. Where those are wrong the
list is too. On 2026-09-27 the be-nl lists still held spare parts tagged for cooking (a heating
element, oven runners) and children's puzzles tagged for gaming. That is a tagging problem, for the
tagging queue, not something to patch here.
- **Six products minimum** (`PersonaTopTen::MINIMUM`), or no list and no section: "top 10" over
  four products is a claim the page cannot back.

The section's hint says all this in a sentence, because a numbered list reads as a claim and the
reader should know whose.

## Wish lists: the privacy rules still hold

Wish-list counts follow the rules of the brand page's wish-list rail
([cove-entities.md](cove-entities.md)): distinct lists, never people; nothing under three lists
(`EntityRails::WISHLIST_FLOOR`), applied in the query so a smaller count never leaves the database.

The one difference is that this list is **stored**, and that rail's rule is "never stored in a
snapshot table". What is stored here is the order of product ids, never a count, and it is replaced
every week. So a deleted list's influence lasts at most a week and no aggregate survives it. The
weekly run is after the nightly personal-data prune, so a list deleted on Sunday no longer counts on
Monday.

## Weekly, and stored

`RefreshPersonaTopLists` runs every Monday from 08:20, one market every four minutes, after the
morning catalogue run (fresh stock and charts). It writes one `persona_top_lists` row per persona
per week (`week` is that Monday). Stored rather than cached, so the list holds for the whole week
and the page can say when it was made ("Bijgewerkt op 28 september").

The page reads the latest row, and:

- drops any product that has gone out of stock since Monday, and renumbers the rest;
- shows nothing when the latest list is more than a week old: the job has stopped, and a "this
  week" heading over it would be untrue.

`php artisan bc:refresh-persona-tops [--market=be-nl]` does it now, inline: after a deploy, and after
publishing new personas, which otherwise wait until Monday.

## Files

- `app/Services/Gift/PersonaTopTen.php` — the pool, the ranking, storing and reading
- `app/Services/Gift/PersonaBudgets.php` — `brief()` and `fits()`, shared with the budget tabs
- `app/Jobs/RefreshPersonaTopLists.php`, `app/Console/Commands/RefreshPersonaTopListsCommand.php`
- `app/Models/PersonaTopList.php`, `database/migrations/2026_09_28_001600_a_persona_keeps_a_weekly_top_ten.php`
- `routes/console.php` — the Monday schedule
- `resources/js/Components/PersonaTopTen.tsx` — numbered rows, not a grid: the order is the point
- `tests/Feature/PersonaTopTenTest.php`
