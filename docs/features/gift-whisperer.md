---
name: Gift Whisperer
area: Gifting
status: Active
date_added: 2026-08-08
---

# Gift Whisperer

Describe someone, get four suggestions, each with the reason it was chosen.

Gifting is **anti-search**: a shopper knows the product and needs the price; a gift-giver knows the
person and has no idea what the product is. Every part of this feature exists because the search box
is the wrong tool for that problem.

## The pipeline

```
AngleMap ──▶ retrieve ──▶ filter ──▶ score ──▶ MMR ──▶ explain
 (queries)    (one SQL)   (hard)     (5 signals)  (diversify)
```

Four suggestions out of tens of thousands of rows, in under 100 ms, on a request that can never cost
an AI call. Everything expensive happened earlier: giftability was classified after the last ingest,
the angle map was widened overnight.

## 1. Giftability — [see the classifier](#the-classifier)

A merchant feed is mostly *not* gifts. Vacuum bags, printer toner, extended warranties and phone
cases for one specific handset vastly outnumber the things a person would be pleased to unwrap. One
of those in a gift result destroys trust in every other result on the page — so the classifier is
tuned strict: a wrongly excluded gift costs one candidate out of tens of thousands, a wrongly
included non-gift costs the feature.

### The classifier

Pure — text and a price in, a verdict out. No database, no network. Two decisions shape all of it:

**Match substrings, not words.** Dutch and German write compounds closed: `stofzuigerzak`,
`inktcartridge`, `waterfilterpatroon`. A `\bcartridge\b` regex matches none of them, so a
word-boundary matcher waves every Dutch consumable straight through — and Dutch is two of our five
markets.

**List the compound, never the bare stem.** The obvious follow-up mistake is adding `filter` to the
list, which then also kills `polarisatiefilter` and `ND-filter` — real presents for someone who takes
photographs. So the list holds `waterfilter`, `stofzuigerfilter`, `filterpatroon`. Camera filters
survive because nothing in the list is a substring of them, not because of a special case bolted on
afterwards.

**A term must never sit in the list beside its own prefix.** Found the hard way: `navulling` and
`navul` both matched a coffee hamper, and the shorter one — reached second, carrying no rescue of its
own — silently overturned the longer one's rescue. Keep the prefix, hang the rescue on it.

Accent folding is done by an explicit table rather than `iconv('ASCII//TRANSLIT')`, which produces
different output on glibc, musl and Windows. Tests run on a Windows laptop; production is Alpine. A
classifier that disagrees with its own test suite depending on the host is worse than none.

The golden file in `tests/Unit/GiftabilityClassifierTest.php` **is** the specification — 35 cases,
each a real shape from the Awin feeds.

## 2. The angle map

Interest (+ optional vibe) → the search queries that retrieve candidates. Two layers:

1. **Curated seed**, compiled into `AngleMap`. Hand-written, and good enough alone — the feature has
   to work on a fresh database, in a test, and with `AI_ENABLED=false`. A gift finder that returns
   nothing until a nightly job has run is broken on launch day.
2. **Widened rows** from `gift_angles`, written nightly by `WidenGiftAngles`.

Widened rows come *first* in the query order. Putting them behind the seed would mean the nightly job
never visibly changes anything until the seed runs out.

The seed uses concrete product nouns, not themes: `statief` and `cameratas` retrieve products,
`cadeau voor fotograaf` retrieves listicles and junk.

Free-text interests are passed through verbatim. Someone who typed "wielrennen" has told us exactly
what to search for, and second-guessing them is worse than trusting them.

### Widening is the AI invariant in miniature

The model runs in a scheduled job, under a daily cap, and writes rows the request path only reads.
Batched one call per market covering the five stalest interests — 5 markets × 36 interests × 4 vibe
states is 400 combinations against a cap of 20 calls a day, and the model writes better queries when
it can see several interests at once. Staleness is read off `updated_at`, so the timestamp *is* the
cursor and it survives a redeploy for free.

With AI off, nothing happens — deliberately. Faking widening from the catalogue would push results
toward what is already well stocked, which is the opposite of the point.

## 3. Retrieval

One query. The angle queries are folded into a single `websearch_to_tsquery` joined with `OR`, not a
subquery per term: twenty `EXISTS` clauses against a table this size is the difference between 40 ms
and four seconds.

**"Avoid" is a hard filter, never a penalty.** Someone who wrote "no alcohol" or "she's allergic to
wool" is not expressing a preference to be weighed against price. A single violation makes the whole
page untrustworthy. `ILIKE` rather than FTS, because the exclusion has to catch the word wherever it
sits — including inside a Dutch compound.

An interest with no matches falls back to a budget-and-vibe browse rather than an empty page. The
person told us who they are shopping for; "we found nothing" throws that away.

## 4. Scoring

| Signal | Weight | Note |
|---|---:|---|
| `interest_fit` | 40 | Half the best single interest answered, half the weighted share of the brief's interests answered (vectorial since 2026-09-14). Interests weigh 1.0 for the first down to 0.5 for the last. A match is an editor's tag or what the search matched; a description-only match is worth half. With four interests: the first alone 0.67, the first two 0.81, the last three 0.75, all four 1.0. |
| `budget_fit` | 20 | Peaks at 85% of the ceiling, falls away on both sides. A €12 gift against a €100 budget reads as thoughtless, not thrifty. |
| `surprise` | 10 | From [the Serendipity Engine](serendipity.md). 20 until 2026-09-14; five points went to `recipient_fit`, five to `occasion`. |
| `vibe` | 10 | A nudge, never a filter — someone who said "playful" still wants the good headphones if headphones are the right answer. |
| `preference` | 5 | Which way their taste goes, as seven axes of two poles: practical/design, modern/vintage, minimal/colourful, natural/technical, manual/powered, everyday/luxurious, classic/quirky. Added 2026-09-14. Any one of the poles named matching is a match, and the pole *not* chosen is never scored against a product. 10 in `for_myself`, where the finish is half the point of wanting the thing. |
| `values` | 10 | Sustainable / local / handmade. The wizard stopped asking on 2026-09-14 (below); a saved person still carries them from their own page. |
| `recipient_fit` | 5 | An editor's `recipient:` or `age:` tag meeting the brief's relationship or age band. No text fallback. See [gift-tags.md](gift-tags.md). |
| `occasion` | 5 | An editor's `occasion:` tag meeting the brief's occasion, or the word in the title. Zero until 2026-09-14: title words alone were too thin to trust. |
| `demand` | **0** | Bestseller-chart strength. Zero here is the decision — see below. |

**`demand` is weighted zero for `for_someone` on purpose.** We hold a real demand signal now (see
[popularity-charts.md](popularity-charts.md)), and this is the one place it must not be spent.
`surprise` exists to stop the best-stocked product winning every tie; paying for popularity alongside
it cancels that out and turns the Whisperer into a chart — while looking like an improvement. The
`for_myself` profile weights it 5, because that is the opposite question: nobody wants a surprising
kettle on their own wishlist, they want the one that turns out to be good. This split is exactly what
`SuggestionProfile` exists to hold, and
`SuggestionEngineDemandTest::chart_data_does_not_move_a_gift_suggestion` asserts the gift output is
byte-identical with and without chart data.

Chart products still *reach* the scorer. The candidate pool is capped at 300 and ordered by
`merchant_count`, and a bestseller pulled from one retailer's chart is sold by that retailer alone —
so it sorted last and fell off the end, meaning the things people demonstrably buy were
systematically absent with nothing in the output to show it. A sixth of the pool is now reserved for
chart-backed groups, through the same query builder so `avoid` and the budget bind identically. It
can add candidates; it cannot reorder them.

**An unanswered question scores 0.5, not 0.** "Does not apply" is not "scores badly" — scoring a
skipped question as zero would silently shrink the total for everyone who skipped it, and the wizard
is built so every step after the first can be skipped.

## 5. Diversification — the stage that matters most

Without MMR the top four are near-duplicates, because whatever scores well scores well *for the same
reasons*. Four Bluetooth speakers at four price points is a worse answer than a speaker, a cookbook,
a plant pot and a board game, **even though each speaker individually beats each alternative**.

Greedy MMR at λ = 0.65: `λ·score − (1−λ)·maxSimilarityToAlreadyPicked`. Similarity is category (0.6)
+ brand (0.2) + Jaccard title overlap (0.4), capped at 1. Category dominates because it is what a
person notices — two headphones from different brands still read as "you showed me headphones twice".

This is tested directly, with a fixture of six speakers that all outscore three genuinely different
presents. A diversifier that quietly stops working looks exactly like one that works, right up until
every result page shows four of the same thing.

## 6. Explaining

One reason per card, not a breakdown. Three reasons read as a machine justifying itself, and the
strongest signal is almost always the true one. The full `breakdown` is kept on `Suggestion` — "why did
it pick this" is the first question everyone asks, the shopper now and whoever tunes the weights in
six months. A recommender you cannot interrogate is one you cannot fix.

## Privacy

The wizard's answers live in component state and travel by POST. A brief describes a real person —
their tastes, what to avoid, what you are willing to spend on them — and that does not belong in a
URL that lands in a referrer header or a shared browser history. The wizard page itself is a GET so
it can be indexed; only the results are POSTed.

A rejection is remembered server-side for the sitting (see below), so "something else" never loops
back to what was just rejected — the fastest way to lose trust in a recommender.

## Files

- `app/Services/Gift/GiftabilityClassifier.php`, `Giftability.php`
- `app/Services/Gift/AngleMap.php`, `GiftEngine.php`, `TasteBrief.php`, `Suggestion.php`
- `app/Jobs/ClassifyGiftability.php`, `WidenGiftAngles.php`
- `app/Services/Gift/RejectionMemory.php`
- `app/Http/Controllers/GiftController.php`
- `resources/js/Pages/Gift/Wizard.tsx`, `resources/js/Components/ChipInput.tsx`,
  `resources/js/Components/SaveToList.tsx` (the `into` prop)
- `tests/Unit/GiftabilityClassifierTest.php`, `tests/Feature/SuggestionEngineTest.php`,
  `tests/Feature/GiftWhispererTest.php`


## "Show me something else" — two defects behind one promise

Fixed 2026-08-16. `gift_cove.whisperer_step2` says *"what you rejected is never offered again"*, and
neither half of that worked.

**The board collapsed to one card.** `swap()` scored with `withLimit(1)` and rendered `picks` as that
single replacement, so the three suggestions the visitor had kept were thrown away by the *render*,
not by the ranker. It now re-renders a full board: because the ranker is deterministic, "top four
minus the one you rejected" **is** the three that were kept plus the next one down — no id
round-trip, no splice, and no trusting a client-supplied ordering of what is currently on screen.

`isSwap` was declared in the props and never destructured. Deleted from both sides.

**The rejection list did not survive its own round trip.** It lived in component state and was posted
back with each swap — but the Wizard posts without `preserveState`, so Inertia rebuilt the component
and the accumulator reset to empty. The first rejection could therefore reappear on the second swap.

`RejectionMemory` moves it to the **session**, not the database: a rejection is a passing opinion
during one sitting, is not worth a row, is not data anybody should be able to ask us for later, and a
table would have to be taught to `bc:prune-personal-data` and then justified in the privacy policy.

The one-word client fix — adding `preserveState` — was considered and rejected. It holds only until
the visitor does something ordinary: a reload, a back-navigation or the "Try again" button all wipe
component state, and the promise is unconditional.

Two further consequences worth stating:

- **Bucketed per brief**, keyed on a hash of the normalised brief. Describing your mother and then a
  colleague in one sitting must not have one poison the other.
- **"Try again" was replaced by "Four more" on 2026-09-13.** It re-posted the same brief, and a plain
  post is idempotent, so it only showed something new when a swap had already thrown something away.
  See the section below. Opening the wizard still flushes everything, which is what "Start over" says.

Both caps on the memory exist because a session store is visitor-controlled input: ~60 ids per brief
and five briefs, LRU.

## Scoring follows the search, and the first interest wins (2026-09-13)

The brief that took the Whisperer out of the menu: "schilderen" plus "techniek" returned speakers
and earbuds. Read against production's data, two causes, and the second was the real one.

- **The stock is thin.** The be-nl products matching "schilderen" with the most sellers are
  children's craft kits, and a games console matched through its description.
- **The ranking let the second interest win, twice over.** First, interest fit was weighted by a
  query's *position in a flat list*: the nightly widening had written the tech angle as "draadloze
  oordopjes, bluetooth speaker, ...", those sat right behind "schilderen", and the first of them
  scored 0.94 of it. Second, the scorer looked for the query text *literally* in the title, while
  retrieval matches on stems. A title saying "schilders" was found by the search and scored zero on
  interest; a title spelling out "bluetooth speaker" scored full marks. Budget fit finished the job:
  paint sets at six to sixteen euros sit far below the sweet spot, headphones do not.

Two changes, both in `SuggestionEngine`:

1. **Per-interest weight.** `AngleMap::queriesByInterest()` keeps the provenance, and the scorer
   weighs the *slot* a match came from: first interest 1.0, last 0.5, spread evenly, a typed search
   query as a slot of its own in front. At 0.5 the second interest is a real second: it decides
   between two answers to the first and wins only when the first has nothing to offer.
   `the_first_interest_outranks_the_second` is the user's brief as a test and fails on the old code.
2. **Matches come from Postgres.** One query over the candidate ids and the terms, the same
   `websearch_to_tsquery` against the same `search_vector`, so the scorer credits exactly what the
   search found. `ts_filter` on weights A-C separates a title, brand or category match (1.0) from a
   description-only one (0.5): the description is where feeds put everything they could think of.
   A CTE parses each tsquery once; 300 groups by 24 terms is a few milliseconds.

Not changed: the reason on the card still names the matched term, and the catalogue is still thin
on adult painting supplies. The first is a declined item, the second is a feed question.

## Out of the header (2026-09-13)

The owner took the Whisperer out of the "Find a gift" menu the same day the changes below shipped:
it does not work well enough to be the most prominent answer to that label. The page stays at
`/gift`, the How-it-works manual still explains it and the legacy redirect still lands on it, so it
has an address but no door in the header, the same status the home page gave it when the search
field replaced its button there. It comes back when the suggestions earn the place. See
[navigation.md](navigation.md).

## Adjust, Four more, and the saved person (2026-09-13)

Five changes to the wizard and one bug fix underneath them, all chosen by the owner from a list of
gaps found by reading the feature end to end. Three offered improvements were declined and are not
done: reasons in the reader's language (a card says "Matches koffiemolen" on every market), matching
multi-word queries in any order, and a notice when the interest matched nothing and the board is a
budget browse. Do not do them unprompted.

### The invariant: every action renders `suggest(brief minus memory)`

`suggest()`, `swap()` and the new `more()` all render exactly that and differ only in what they add
to the session memory first: nothing, the one rejected id, or the board on screen. Because the
ranker is deterministic, the board a visitor is looking at can always be recomputed on the server,
and **nothing in the controller trusts a client-supplied list of ids** — a list that could just as
well name the four the visitor wanted to keep.

This exposed a bug. `swap()` used to remember the whole board it returned, so that a "Try again"
re-post would show something new. The side effect was that the *second* swap excluded the three
cards the visitor had kept and replaced all four. The docblock's promise ("the three that were kept
plus the next one down") held for the first swap only, and no test asserted the kept three survived.
`a_second_swap_keeps_the_three_you_did_not_reject` now does, and `swap()` remembers exactly the one
opinion it was given.

**"Four more"** (`POST /gift/more`) is the explicit way past a board: recompute what is on screen,
remember those ids, suggest again. Two engine runs per press, each well under 100 ms.
`four_more_after_a_swap_does_not_skip_a_board` is the oracle: after a swap, the next board must equal
the engine run directly with the rejected id and the current board excluded, which is only true if
the swap did not poison the memory. The memory's cap of 60 ids per brief gives 15 presses before the
oldest board is evicted; past that the brief is the problem, not the picks.

### Adjust, not Start over

The results used to hide the answers entirely, so disliking one card meant "Start over" and six
questions again — while the controller's own comment claimed the wizard "keeps its answers on screen
next to the results". Now a line of chips above the cards says what was answered (for whom, the
interests, the feel, the budget, the avoid words, the values) with an **Adjust** button that returns
to the questions with the answers kept. No request: component state was already the truth, and the
results stay in props for "Back to the ideas".

### Words of your own

The engine has always searched a free-text interest verbatim, and this document said so, but the
wizard only offered the twenty chips. An "Anything else?" box on the interests step now adds a word
as a chip; the `ChipInput` component was lifted out of the avoid step so both use one implementation.
The cap of eight interests is enforced in the wizard as well as on the server, because the server's
`max:8` is a 422 that puts nothing on screen: refusing the ninth word at the input is the only place
the visitor can see the limit.

### Five steps when nobody is saved

"Who is it for?" exists to offer the people already described. With nobody saved it was a step with
one button. It is now filtered out of the step list rather than skipped over, so the counter ("Step 1
of 5"), Back and Next all stay correct without knowing it was ever there. The How-it-works copy said
"six short questions" and now says "a few".

### The saved person is actually used

Picking a saved person copied their answers into the form and stopped there: `recipient_id` was never
posted, so the controller's overlay from the stored profile and its `remember` write were dead code.
The id now travels with every request.

- **The overlay contract.** `brief()` fills only *absent* keys from the profile (`+=`). A key the
  wizard posted wins even when it is `[]` or `null` — Laravel's `validated()` keeps an empty array —
  so clearing "avoid" really clears it, while the occasion and age band, which the wizard never asks,
  still come from what is stored. The wizard posts every key it edits for this reason, and
  `a_cleared_answer_beats_the_stored_one` pins it.
- **Remember is opt-in**, off by default, shown only when a saved person was chosen, on the last
  question and on the results (where a tick re-posts the brief at once; a plain post is idempotent,
  so the board does not move). The controller's original reasoning stands: a brief for something
  silly for the office must not become Mum's profile.
- **The label says "answers", not "taste"**, and the hint says what it cannot do. `describeTaste()`
  refuses when the person has described their own taste through their link (`TasteSource::Self`
  outranks `Suggested`). The budget is the *giver's* fact, not the person's taste, so it is written
  directly and survives that gate — without it, "use what we know about Mum" restored everything
  except what you spend on her.

### Saving lands on that person's list

How-it-works promised "save the good ones straight onto a list for that person"; the Save button
landed wherever the last save went. The controller now returns `recipientList` — the chosen person's
`for_someone` list, made if missing — and `SaveToList` takes it as a new `into` prop that outranks
adding mode and the remembered last list. A group list never qualifies: it is a shortlist other
people are paying into, not somewhere to file research.

Resolved on the server rather than lazily from the Save button because `SaveToList` reloads the
shared `lists` prop after creating a list with a partial GET of the current page — which on this page
is `/gift` after a suggest (and `show()` flushes the rejection memory on the way in) or `/gift/swap`
after a swap (a 405). Signed-in owners only: an anonymous visitor cannot save, so a list they could
never use would be noise in the picker. `RecipientProfileController::theirList()` is the precedent.

## The vibe step asks which way their taste goes (2026-09-14)

The owner's ask was "practical vs design, modern vs vintage, useful vs beautiful", and the first
answer here was a flat list of looks. The correction came within the hour — "it's more than style"
— and it is the design: the *vs* is the point. A taste is a handful of choices between two ways a
present can go, and a person recognises their own by being shown both ends rather than by reading
a bag of adjectives and picking none.

`App\Enums\Preference` is seven axes of two poles: practical/design, modern/vintage,
minimal/colourful, natural/technical, manual/powered, everyday/luxurious, classic/quirky. Manual or powered is a real fork in a present — a hand grinder and an electric
one are different gifts for the same shelf — and it is the one axis a feed title almost always
answers by itself. The wizard draws one row per axis inside
the vibe step — not a step of its own, because every step after the first is one people skip —
with up to three chosen across the whole answer, and picking one end clears the other because
nothing is both modern and vintage. A saved person remembers them in `recipients.preferences`,
next to the vibe and the values they already remembered.

The first axis is the owner's headline pair, practical or design, and it asks the vibe question
again on purpose: it belongs in the form the rest of the taste is asked in. The vibe itself does
not change — one pick of three, and what thousands of products are already tagged with — so a
product can carry both, and two signals that agree are the same fact said twice. The other six
axes are everything the vibe cannot carry at all.

Scored like `values` and for the same reason: an editor's `preference:` tag first, the title words
as a fallback (a feed says "eiken" far more often than anyone tags `preference:natural`), and a
neutral 0.5 when the question was not asked, so skipping it costs nothing. The opposite pole is
never scored against a product: a cosy present shown to someone who said "sleek" is merely not
what they asked for, and the rest of the brief judges it better than this signal would.

## A board of eight, spread across the interests (2026-09-14)

Three changes the owner asked for together, because they are one complaint.

**Eight cards, from four.** Four is a board you take in at a glance and also a
board where one wrong guess is a quarter of the answer. `giftcoves.gift.results`
is the single place the number lives; the copy that counts them out loud
("Acht ideeën", "Acht andere") follows it by hand.

**Each interest gets a share of the board.** "Painting and technique" came back
as a page of speakers: the diversifier spread the board across *categories*,
which is not the same as spreading it across what the person said. Scaling the
similarity penalty could not fix it — two products of one interest already look
alike to that term, so the interest a board opens on keeps winning on raw score
long after it has said everything it has. So `diversify()` gives each interest
`limit ÷ interests with candidates` seats, rounded up, and while any interest is
under its share only those candidates are eligible. When every interest has had
its share, or nothing eligible is left, the pool opens again and the ranking
finishes the board — so an interest with two good products and a share of four
hands the spare seats back rather than leaving the board short.

**The values question is gone.** "Anything that matters?" — sustainable, local,
handmade — was the last thing standing between a person and their suggestions.
The signal stays: a saved person sets values on their own page and the brief
picks them up server-side, so `valuesFit` still scores. It is the question that
went, not the answer.

## The card names what it fits, and says nothing else (2026-09-14)

A card used to carry its strongest signal as a sentence: *Past bij koken*,
matches cooking. The owner cut the wording — saying *that* a suggestion fits
adds nothing a shopper cannot see, and the words around the fact crowd out the
fact. The card now lists what the present has in common with the brief:
the interests it answered first, then the taste poles and values it sits at,
capped at four.

`Suggestion::fits()` builds the list and `SuggestionEngine::matchedTastes()`
works out the taste half by asking each pole the brief named on its own, an
editor's tag first and a title word second. Only what was asked for is ever
listed — a product tagged `preference:vintage` says nothing on a card for
somebody who never mentioned vintage — because the line is about the overlap,
not about the product. The values go to the page raw and the page labels them,
so an interest somebody typed in their own words still reads back in their own
words.

## The wizard asks the age, from fixed groups (2026-09-14)

A seventh question, after the interests: "How old are they?", nine chips (0-2, 3-5, 6-9, 10-12,
13-17, 18-29, 30-49, 50-64, 65+), skippable like every step after the first. The answer is one of
the exact strings an editor tags a product with (`age:13-17`), so `recipient_fit` compares two
fixed values and nothing is typed or folded. The server refuses anything else. See
[gift-tags.md](gift-tags.md).
