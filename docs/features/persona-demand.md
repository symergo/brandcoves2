---
name: Personas from what people search for
area: Gifting / Content
status: Active — gift searches counted from 2026-09-26; drafts nightly at 06:30 per published market; drafts only
date_added: 2026-09-26
---

# Personas from what people search for

The owner's request 6 (2026-09-26). When people keep searching for a gift for the same kind of
person ("cadeau voor mijn zus die van yoga houdt", "papa die alles al heeft") and nothing on the
site answers that person yet, the site drafts a gift persona for it: a plan in the Cove planner
with a gift brief, a placeholder title and eight products, for a person to rename, curate and
approve. A **persona** is a Cove about a kind of person rather than a day, at
`/{market}/gift-ideas/{slug}` ([gift-personas.md](gift-personas.md)).

Nothing publishes on its own. The job writes `draft` plans; approving and building stay a person's
decision, exactly as for every other plan.

## Counting: readings, not searches

A gift search is answered by the suggestion engine and **never reaches `search_log`**
([intent-search.md](intent-search.md)), so the site's demand signal was blind to exactly the
searches a persona answers. Two things fixed that:

- **`gift_search_demand`**, a new table. The search page writes one row per market, day and
  *reading*: who (`relationship`), what they love (`interest`) and whether they "have everything".
  The count goes up; the words, the budget and the visitor are not kept. A search naming two
  interests counts once for each. Crawlers (`TrackAnonymousIdentity::isMachine()`) are not
  counted, because the chips under a gift search are links a crawler can follow. A search that
  names nobody's interest ("a present for dad") is not counted either: that is the landing page's
  job, not a persona's.
- **`search_log`, read back through `GiftIntentParser`**: the short gift searches from before the
  box learned to read them, and every one run "as words". Bounded to the 2,000 most searched
  queries of the window (`persona_demand.log_rows`).

`gift_search_demand` holds nothing personal, so it is not in the published retention list; the job
still trims rows older than a year, the retention `search_log` has, so the table does not grow
forever.

## The bar (`config/giftcoves.php`, `persona_demand`)

- **5 searches in 90 days, on at least 3 different days.** Five is the bar the list signals and
  offline ideas use; the days rule is there because a search count is weaker evidence than five
  list owners. One person, or one crawler, searching the same thing nine times in an afternoon is
  not demand.
- **An interest, or "has everything".** A persona is about what somebody loves.
- **Eight products must fit** (the owner's minimum for a persona), by the engine's own verdict:
  products matching the interest, or used up or done for a has-everything brief
  ([has-everything.md](has-everything.md)). "The engine returned something" is not enough, since
  with nothing matching it falls back to a budget browse. The same rule the landing pages use.
- **Three drafts per market per night**, the most searched first. The rest wait for the next night,
  so a burst gives an editor a short list to read rather than a planner full of placeholders.

## No duplicates

A reading is skipped when something already answers it:

- **A landing page**: `gift_landings` has the pair (recipient × interest)
  ([gift-landing-pages.md](gift-landing-pages.md)). A has-everything reading never has one.
- **A persona plan in the market, whatever its status.** With a brief, by the brief: its interests
  include the reading's, and it is about the same person or about nobody in particular ("the yogi"
  answers "sister + yoga"). Without one (the hand-written personas chosen by search terms), by what
  it is about: its drawing names the interest, or its title or slug contains the interest's label
  or one of the search box's synonyms for it, so "De thuiskok" is seen to be about cooking.
- **A rejected draft still counts.** Rejecting it is what stops it coming back. Deleting it does
  not: a deleted draft is drafted again the next night if the demand is still there.

### What this means in practice

Landing pages already exist for every recipient × interest pair the catalogue can fill with eight
products. So "zus + yoga" and "papa + koken", the owner's own examples, are **normally answered by a
landing page and not drafted**. The drafts that do appear are the readings no landing page covers:
an interest searched without a recipient ("cadeau yoga": a persona about the yogi), and anything
with "has everything". That is the owner's rule applied as written ("no persona and no landing page
yet"), not a limitation of the code; relaxing it is one condition in `PersonaDemandPlanner`.

## The draft

- `kind = persona`, `status = draft`, `pick_mode = open`, the brief as `TasteBrief::toArray()`.
- **A template title, never a model's**: "Je broer of zus die van yoga houdt" (the landing page's
  bare title), "Yoga en meditatie — cadeau-ideeën" (the drafter's existing placeholder), "Wie
  alles al heeft", "Papa, die alles al heeft". The note says where it came from (searches and days)
  and asks for a real title before approval.
- **A drawing** when the interest has one (cooking, coffee, …) and `has_everything` for that
  reading; otherwise none, which draws a figure.
- **Eight products**, the builder's own choice for the brief (`EditionBuilder::candidates()`),
  excluding what this night's earlier drafts took.

## AI

None. The title is a template, the products are retrieval and arithmetic, and it runs in a queued
job; with `AI_ENABLED=false` it works the same. `PersonaDemandTest` runs it with an AI client that
fails the test if anything calls it. The prose is written later like every other Cove's: in a
session, sent authored over the editorial API.

## Running it

- Nightly: `PlanPersonasFromDemand`, 06:30, per published market, after the landing pages so a
  pair that got its page tonight is not also drafted. The landing pages are a step of the morning
  catalogue run since 2026-09-28 (05:40 before), which on a normal night is through the markets by
  then. The persona drafts stay a separate schedule entry, not a step of that chain: if tonight's
  run is late, a new pair may be drafted once and should be rejected.
- By hand: `php artisan bc:plan-demand-personas --market=be-nl` is a dry run that lists what would
  be drafted and why the rest were skipped; `--write` creates the drafts.

## Measured

Nothing yet: `brandcoves_prod` (the scrubbed copy) holds no `search_log` rows, and gift searches
were never counted before this change. The first real numbers come from production after a few
weeks of `gift_search_demand`.

## Files

- `app/Services/Gift/GiftSearchDemand.php`, `app/Services/Cove/PersonaDemandPlanner.php`
- `app/Jobs/PlanPersonasFromDemand.php`, `app/Console/Commands/PlanDemandPersonasCommand.php`,
  `routes/console.php`
- `app/Http/Controllers/SearchController.php` (the count)
- `database/migrations/2026_09_27_000400_gift_searches_are_counted_for_personas.php`
- `config/giftcoves.php` (`persona_demand`)
- `tests/Feature/PersonaDemandTest.php`
