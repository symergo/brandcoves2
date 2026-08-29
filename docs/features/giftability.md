---
name: Giftability
area: Gifting / Catalogue
status: Active
date_added: 2026-08-29
---

# Giftability

Decides whether a catalogue row is something you could give someone — and, separately, whether it is
something worth putting on a page at all.

Pure: title, category and price in, a verdict out. No database, no network, no market lookup. That
is what lets a golden file pin it down, and it is where the subtle bugs live.

## The problem it solves

A merchant feed is mostly *not* gifts. Vacuum bags, printer toner, phone cases for one specific
handset and replacement filters vastly outnumber the things a person would be pleased to unwrap. One
of those in a gift result destroys trust in every other result on the page, so the classifier is
tuned strict: a wrongly excluded gift costs one candidate out of tens of thousands, a wrongly
included non-gift costs the feature's credibility.

## Two verdicts, because there are two questions

Changed 2026-08-29. One boolean used to answer both, and got the second one wrong.

| Column | Who reads it | Carries the price ceiling |
|---|---|---|
| `giftable` | `SuggestionEngine`, `ListQuizController` | **yes** |
| `worth_showing` | `SerendipityEngine` (and therefore `/surprise`, the Cove, `OutlierRetriever`), the deals column | no |

`>€500 is a decision rather than a suggestion` is a sound rule for a gift finder and plainly wrong
for the editorial surfaces, where an expensive unusual object is exactly what people came to look
at. The same flag gated both, so on the dev catalogue **9,040 rows — the single largest rejection
bucket, 48.5% of all rejections — were being kept off the Cove and `/surprise` for a reason that
does not apply there.**

Everything else excludes a row from both. A printer cartridge is no more interesting than it is
giftable, which is why `worth_showing` is not simply "has a price and an image".

Both columns are nullable and both readers test `=== false` / `= true`, so a row that has never been
classified is *unknown* rather than rejected. Half the catalogue is in that state at any time.

## Order of the rules

Price first — cheapest check, least arguable, and it settles 85% of rejections on its own:

| Reason | Rows | Share of rejections |
|---|---:|---:|
| `too_expensive` | 9,040 | 48.5% |
| `no_price` | 5,080 | 27.3% |
| `too_cheap` | 1,684 | 9.0% |
| `consumable` | 1,568 | 8.4% |
| `fitment` | 689 | 3.7% |
| `bulk` | 456 | 2.4% |

Then the disqualifying terms, then the bulk patterns.

## Two decisions that shape the term lists

**1. Match substrings, not words.** Dutch and German write compounds closed: "stofzuigerzak",
"inktcartridge". A `\bcartridge\b` regex matches none of them, so a word-boundary matcher waves every
Dutch consumable straight through — and Dutch is two of five markets.

**2. List the compound, never the bare stem.** The obvious follow-up mistake is to add `filter`,
which then kills "polarisatiefilter" and "ND-filter" — real presents for someone who photographs. So
the list holds `waterfilter`, `filterpatroon`, `stofzuigerfilter`. Camera filters survive because
nothing in the list is a substring of them, not because of a special case bolted on afterwards.

Where a compound is still ambiguous, `RESCUES` carries the exception — keyed **by term, not by
group**, because rescuing at group level would let a lens filter drag printer toner in behind it.

## What was removed, and what it cost

Also 2026-08-29. `spare_part`, `service` and `household_staple` rejected **114 rows between them out
of 63,508 classified** — 0.18%, for three hand-maintained multilingual term lists that someone has to
keep current per market. Removed.

Recorded rather than left to be discovered: a warranty extension, a software subscription and a
cordless-drill battery now pass as gifts. They sit in the golden file as *passing* rows, so if that
turns out to matter the evidence is already written down.

Staples mostly still fail, because a staple is always sold by the count and the bulk patterns read
counts. One roll-unit pattern (`24 rollen`, `18 rolls`) replaced fourteen product names and
generalises to the staples nobody thought to enumerate — the structural signal doing the work the
lexical list was doing badly.

`consumable` and `fitment` stay despite also being small. Their rows are not randomly distributed: a
cartridge scores well on every rarity signal, so they are concentrated exactly where the discovery
surfaces would otherwise have surfaced them. The count understates the value.

## Where it runs

`ClassifyGiftability`, after grouping — the classifier reads the group's denormalised title, category
and cheapest price, all of which grouping is what produces. It rewrites **every** row rather than
only new ones: the rules change more often than the catalogue does, and a partial pass would leave
the old verdict on 60,000 rows with no way to tell which.

One `UPDATE ... FROM (VALUES ...)` per 1,000-row chunk. A per-row update over 70,000 groups is 70,000
round trips. The VALUES list carries explicit `::boolean` casts because PDO sends every bound
parameter as text and Postgres will not coerce text into a boolean inside a `CASE`.

## Files

- `app/Services/Gift/GiftabilityClassifier.php` — the rules
- `app/Services/Gift/Giftability.php` — the two-part verdict
- `app/Jobs/ClassifyGiftability.php` — the pass
- `app/Models/ProductGroup.php` — `scopeGiftable()`, `scopeWorthShowing()`
- `tests/Unit/GiftabilityClassifierTest.php` — the golden file
- `tests/Feature/ClassifyGiftabilityTest.php`

## Known gap

`amazon_products` has its own `giftable` column with a scope reading it and **nothing writing it**.
Either the Amazon rows should go through this classifier or the column should go; today the scope
silently matches nothing.
