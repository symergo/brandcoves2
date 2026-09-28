---
name: Occasion Coves
area: Gifting / Content
status: Active — the kind exists; the first twelve occasions are written for be-nl and nl-nl
date_added: 2026-09-28
---

# Occasion Coves

**Gifts for an occasion: Moederdag, a housewarming, a retirement.** The owner asked for writing per
occasion (2026-09-28) and ruled that an occasion is **not a persona**: a persona is a kind of person,
an occasion is a day or an event. So it is its own Cove kind, `CoveKind::Occasion`, with its own
address and its own row on the shelf.

## The same page as a persona

Everything a reader sees is the persona page (`GiftIdeas/Persona`): an opening, a paragraph per
product with its card under it, the budget tabs, the ideas without a shop and the weekly top 10
([persona-top-ten.md](persona-top-ten.md)). It is built by `EditionBuilder::buildPersona()`, which
now builds whatever kind the plan is, planned and curated on the same screens, and written from its
own prompt slot (`cove.occasion`, `Defaults::OCCASION_SYSTEM`).

`CoveKind::isGiftColumn()` is the question the shared places ask: persona or occasion. "Persona"
keeps meaning a kind of person where only a persona will do: the persona row on the shelf, Find a
gift's "start from a type", the Discover persona row, and the drafts from search demand.

## Its own address and row

- **`/{market}/gift-ideas/occasion/{slug}`**, for example `/be-nl/gift-ideas/occasion/moederdag`.
  The `occasion` segment keeps it clear of persona slugs, as `for` does for the recipient pages. A
  persona is not reachable at an occasion address and the other way round.
- **A row of its own on /gift-ideas**, "Per gelegenheid", under "Per type persoon". The headings
  appear only once there is an occasion, so until then the shelf is the one row it always was.
  Occasions are listed in the order they were published, which is the editorial order.
- In the sitemap, in the hreflang pairing (twins pair on the slug, as personas do), and in the
  weekly top-10 job.
- **A section of its own on /coves and a band of its own in the Cove rail**, "Cadeaus per
  gelegenheid", with its own icon (a party popper). They first shared the persona section, whose
  description says "built around a person", which made Moederdag read as a person; the owner asked
  for them to be split (2026-09-28). The persona description now names real personas, relations
  included.

## No date, on purpose

An occasion carries no date and the page never states one. Moederdag is the second Sunday of May in
most of Belgium and 15 August in Antwerp; Vaderdag differs between Flanders and Wallonia. A market is
not a region, so any single date would be wrong for part of the readers, which is the same reasoning
`App\Services\Wishlist\OccasionDate` records. Ordering the row by the next occurrence would need
exactly such a date, so the row keeps the editorial order instead.

## Not drafted automatically

`PlanDrafter` refuses the kind with a reason, and the Automation grid's `plan` cell is disabled for
it (`AutomationSettingsStore::applies()`), as for advice, shop and brand: there are a dozen
occasions, written by hand. The other automatic stages default to off for a new kind, so nothing
publishes an occasion by itself.

## The gift brief

An occasion plan's brief should carry interests as well as the occasion, like the relation personas
([gift-personas.md](gift-personas.md)): with no interests, `PersonaBudgets::fits()` accepts
anything, and the budget tabs and top 10 under the page would be a random browse.

## Files

- `app/Enums/CoveKind.php` — `Occasion`, `isGiftColumn()`, the path
- `app/Enums/CoveScene.php` — default drawing (a calendar day) and the allowed ones
- `database/migrations/2026_09_28_002000_an_occasion_is_a_cove_too.php` — the two kind CHECKs
- `app/Http/Controllers/GiftIdeasController.php` — `occasion()`, the shared `page()`, the shelf rows
- `app/Services/Ai/Prompts/Defaults.php`, `app/Services/Ai/PromptBank.php` — `cove.occasion`
- `app/Services/Seo/Alternates.php`, `app/Http/Controllers/SitemapController.php`
- `resources/js/Pages/GiftIdeas/Index.tsx` — the two rows
- `tests/Feature/OccasionCoveTest.php`

## Also fixed on the way

`ScheduleConflicts` named every plan without a date after today ("already on 28 Sep"), because
parsing a missing date gives now. It now uses the title for any undated plan, not only personas.
