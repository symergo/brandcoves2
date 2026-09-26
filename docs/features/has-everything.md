---
name: Someone who has everything
area: Gifting / Search / Content
status: Active — a brief flag the engine, the search box, the Gift Finder, personas and offline ideas understand
date_added: 2026-09-26
---

# Someone who has everything

The owner's request 8 (2026-09-26). The person who has everything does not need another thing to
keep. They are served by what gets **used up or done**: a tasting box, a refill, a workshop, a
subscription, good chocolate, a voucher for a day out.

## One flag on the brief

`TasteBrief::$hasEverything` (stored as `"hasEverything": true`, only when set). Everything else
reads it:

- **The suggestion engine** retrieves on a slot of used-up-or-done words first (behind a typed
  query, ahead of any interest), then puts those products **first**: the board is filled from them,
  and only the seats they leave go to anything else. A strict preference, not a score bonus,
  because a bonus is weighed against price and surprise and loses to a well-priced gadget, which is
  the one present this person does not need. The slot names no interest, so no card shows it as
  one. `Suggestion::$consumable` says which products qualified.
- **The search box**: `GiftIntentParser` reads "die alles al heeft", "heeft alles al", "who has
  everything", "qui a déjà tout", "que lo tiene todo" and their variants
  (`lang/*/intent.php`, `has_everything`). It is a sign of a gift search on its own, it is taken out
  of the words so "alles" is not searched for, and it shows as a chip ("Heeft alles al") that drops
  itself.
- **The Gift Finder**: "heeft alles al" typed as a free-text interest becomes the flag rather than
  a search for titles containing "alles".
- **Offline ideas**: for a has-everything brief, an approved idea whose wording is done or used up
  ("Een workshop koken") fits whatever it is tagged with, and weighs as much as a shared interest.
  Only approved ideas, as always ([offline-ideas.md](offline-ideas.md)).
- **Cove plans**: the builder fills a brief's open slots from the engine, and a has-everything
  brief keeps its used-up products even when they answer none of the brief's interests.

## What counts as used up or done

`resources/content/has-everything-words.php`, read by `App\Services\Gift\HasEverything`. Two lists:
the words the engine searches on, per language (short and common, because each is one OR in a
search that stops being selective past about 24), and the words that decide a product *is* one, in
every language together, matched with the same rules as `interest-words.php` (the matching is
`InterestGuesser`'s, reused).

Left out on purpose: "box" and "set" (they make the shelf ordinary things), "the" and "te" (French
and Spanish tea, but an English article and a Spanish pronoun), "gourmet" (in Dutch a gourmetstel is
a table grill). Compounds that do not start with the word ("wijnproeverij") are missed by both lists, the
same limit `interest-words.php` has (the stemmer does not split Dutch compounds either): add the
compound itself when one matters.

Refills are in on the owner's word, although `interest-words.php` lists them under `not_gifts` for
This or that. The two lists answer different questions: "is this a present at all?" there, and
"what does someone with everything still use?" here.

## The persona

A has-everything persona is planned and curated like any other:

- **From search demand**: readings with "has everything" become draft personas with the flag
  ([persona-demand.md](persona-demand.md)).
- **Over the editorial API**: `"brief": {"hasEverything": true}`, optionally with a relationship.
- **In the planner**, which has no brief field: a persona drawn as `has_everything` with **no brief
  and no search terms** is read as the has-everything brief (`CovePlan::tasteBrief()`). Only then:
  the personas written with search terms (`wie-alles-al-heeft`, `has-everything`) keep choosing by
  them, because re-choosing a live shelf on its next rebuild is a bigger change than this request.

Its budget tabs ([persona-budgets.md](persona-budgets.md)) hold only used-up or done things, and it
shows approved offline ideas that fit.

`PlanDrafter`'s "draft personas" button was left alone: it walks the interests in order and its
tests pin the first one, and the other three routes cover the need.

## Files

- `app/Services/Gift/HasEverything.php`, `resources/content/has-everything-words.php`
- `app/Services/Gift/TasteBrief.php`, `SuggestionEngine.php`, `Suggestion.php`
- `app/Services/Search/GiftIntentParser.php`, `ParsedIntent.php`, `lang/*/intent.php`
- `app/Http/Controllers/SearchController.php` (the chip), `GiftController.php` (free text)
- `app/Services/Ideas/OfflineIdeaPicker.php`, `app/Models/CovePlan.php`,
  `app/Services/Cove/EditionBuilder.php`
- `tests/Feature/HasEverythingTest.php`
