---
name: Gift tags
area: Catalogue / Gifting / Editorial
status: Active
date_added: 2026-09-14
---

# Gift tags

> **Since 2026-09-29** there is no vibe (Handig / Leuk / Mooi) and no values (sustainable / local /
> handmade) anywhere; taste is the pairs of opposites only. See [taste-pairs.md](taste-pairs.md).
> Mentions of either below are history.

An editor's tags on a product, from a closed vocabulary, saying who it is for, what they are into,
which occasion, how old they are, how it feels and what matters about it. Read by the Gift
Whisperer ahead of any text match.

## Why tags, and why a closed vocabulary

The Whisperer matches a brief to products through words: an interest becomes search queries, a
query matches titles. That is a guess, and it is why "schilderen" returned speakers. A tag is a
decision: an editor looked at the product and said `interest:coffee`. The engine can trust it at
full strength and needs no word in the title to agree.

The vocabulary is closed because a tag is only worth having when the wizard can ask for exactly
it. A free-text "persona blurb" could neither be searched nor matched; `interest:coffee` meets the
brief's "coffee" without anything in between. Every vocabulary is one the site already speaks:

| Vocabulary | Values | Source |
|---|---|---|
| `interest` | the forty-four wizard interests (twenty broad ones, sixteen hobbies added 2026-09-14: art, cycling, board games, drinks, baking, running, yoga, cars, science, water sports, winter sports, football, collecting, nature, fishing, horses; then hunting, the first value proposed by the tagging pass itself; then gadgets and IT, split off from `tech`, which was carrying a clever little thing, a computer and everything else with a plug all at once; then, from the gift-set tagging of 2026-09-30, sweets, stationery, pub games (darts, cards) and adult; and perfume, split off from beauty because in Swipe gifts a perfume almost never came up (a perfume keeps `beauty` as well). Adult products appear like any other gift, owner's call, except for a child: `gift_landings.excluded_pairs.child`) | `App\Enums\Interest` |
| `occasion` | the list occasions except `other`, plus sinterklaas, easter, new_year, halloween, communion, christening, engagement, get_well, new_job, secret_santa | `App\Enums\EventType`, `GiftTags::EXTRA_OCCASIONS` |
| `recipient` | partner, mother, father, grandparent, child, friend, colleague, sibling, teacher, host | `App\Enums\RecipientType` |
| `gender` | male, female — only on a product genuinely for one ([gift-gender.md](gift-gender.md)) | `App\Enums\Gender` |
| `age` | 0-2, 3-5, 6-9, 10-12, 13-17, 18-29, 30-49, 50-64, 65+ | `GiftTags::AGE_BANDS` |
| `vibe` | practical, playful, beautiful | `App\Enums\Vibe` |
| `preference` | seven axes, two poles each: practical/design, modern/vintage, minimal/colourful, natural/technical, manual/powered, everyday/luxurious, classic/quirky | `App\Enums\Preference` |
| `values` | sustainable, local, handmade | `GiftTags::VALUE_OPTIONS` |

A tag is `<vocabulary>:<value>`. The whole list is `GiftTags::all()`, and `GET /products/untagged`
returns it beside the products, so a writer never has to guess a spelling.

**The vocabulary grows from the tagging, not only from the wizard.** A writer working a batch may
invent a value in any vocabulary when nothing on the list fits — `interest` and `occasion` most
often, and `preference` too (confirmed by the owner, 2026-09-18; nothing in the code treats one
vocabulary as closed and another as open). A preference is an **axis of two poles**, so propose the
pair rather than a lone pole: half an axis has nothing to be scored against. `POST /products/tags` still
refuses anything outside the vocabulary, so an invented value is a **proposal**: the poster
collects the rejected spellings with their counts, and the ones that recur are added here and the
batch re-posted. `interest:hunting` is the first that arrived this way (five products on be-fr with
no interest that fitted, 2026-09-14). The rest of that round was noise worth naming: age ranges the
drafters made up rather than picking a band (`age:6-12`), and duplicates of a vocabulary that
already covered it (`recipient:baby`, which `age:0-2` says better).

## Two decisions the owner asked about (2026-09-14)

**No gender.** A friend or a colleague has no gender in the vocabulary, on purpose. "Mother" and
"father" are relationships. A product an editor would tag "for women" is a stereotype more often
than a fact, and where a product genuinely is gendered (a razor, a dress) its title says so and
search finds it. If a market ever needs it, it would be a separate `audience` vocabulary, not a
split of `recipient`.

*2026-09-29:* that separate vocabulary exists now, as `gender` (male, female), after a one-day split of
`recipient` by gender was reverted for doubling the tags. See [gift-gender.md](gift-gender.md).

**Taste is pairs of opposites, and the pairing is the point.** Added 2026-09-14 on the owner's
ask ("practical vs design, modern vs vintage, useful vs beautiful"). The first attempt was a flat
list of looks — modern, vintage, cosy — and the owner's correction was the design: "it's more than
style". A taste is not a bag of adjectives. It is a handful of choices between two ways a present
can go, and a person recognises their own by being shown both ends.

So seven axes, two poles each, and nothing is ever both:

| Axis | One way | The other |
|---|---|---|
| purpose | practical | design |
| era | modern | vintage |
| tone | minimal | colourful |
| material | natural | technical |
| power | manual | powered |
| spend | everyday | luxurious |
| character | classic | quirky |

Warmth (cosy or sleek) was an eighth axis for a few hours and the owner cut it: a blanket is cosy
because of what it is, so the axis was describing the product rather than the person holding the
taste. Manual or powered stays for the opposite reason — a hand grinder and an electric one are
the same object answered two ways, and which one somebody wants is a fact about them.

**The purpose axis is deliberately the vibe question again.** The owner asked for
"practical vs design" twice, and it belongs in the form the rest of the taste is asked in. Vibe
stays as it is — practical, playful or beautiful, one pick of three, the question that most
changes the answer, and what thousands of products are already tagged with. A product may carry
both `vibe:practical` and `preference:practical`; two signals that agree are not a contradiction,
they are the same fact said by an editor twice, and the engine weighs each on its own. The other
six axes are what the vibe cannot carry at all: a practical present can be modern or vintage, a
beautiful one everyday or luxurious. An editor tags a product with the poles it sits at. The wizard draws each
axis as its two ends inside the vibe step, up to three across the whole answer, and picking one
end clears the other; a step of its own would be a step people skip. Neither side is better —
"everyday" is not a lesser "luxurious", it is a different present — so the engine never scores a
product against the pole that was *not* chosen.

**Age is its own vocabulary, as fixed groups of years.** The bands are ranges (owner's call: real
age groups, fixed on both sides, nothing typed): 0-2, 3-5, 6-9, 10-12, 13-17, 18-29, 30-49, 50-64,
65+, cut where presents change. An editor tags the range a product suits, and the wizard asks the
giver to pick one of the same ranges as a step ("How old are they?"), so the two meet as the same
string and nothing has to be folded. An age band stored on a person from before the groups existed
matches nothing and scores neutral. Splitting age from `recipient` is what lets `recipient:child`
mean "their child, who may be forty".

## Storage and retrieval

`product_groups.gift_tags`, a jsonb array, empty by default, with a GIN index. Untagged is the
empty array (nearly every row until the whole-catalogue pass of 2026-09-28, below). Nothing automated writes it: not the grouper, not a job,
not a model. The engine asks `gift_tags ?| ARRAY[...]`, written `??|` in PHP because a bare `?`
is a placeholder to PDO; the default jsonb operator class indexes it. Until 2026-09-27 it was
spelled as the function `jsonb_exists_any()`, which means the same and which no index serves: the
index had never been scanned ([speed.md](speed.md)).

## How the Whisperer reads them

- **Retrieval**: each interest retrieves its own share of the candidate pool, as a text match OR
  that interest's tag; a slot's tag branch is scoped to its own interest so it cannot hand its
  share to another. A tagged product is found whether or not its title agrees.
- **Interest fit**: a tag on an interest is a match at full strength on that interest's slot,
  ahead of any text strength. `interest:coffee` on the product and "coffee" first in the brief is
  the strongest evidence the engine gets. The signal is vectorial (owner's call, 2026-09-14): half
  the best single interest answered, half the weighted share of the whole brief answered, so a
  product tagged for three of four interests beats one tagged for the first alone. The arithmetic
  is on `SuggestionEngine::interestFit()` and in gift-whisperer.md.
- **Occasion, vibe, preference, values**: the tag answers before the title words are looked for.
  Occasion weighs 5 now, from zero: title words were too thin to trust, an editor's tag is not.
  A `preference:` pole weighs 5 buying for someone else and 10 on your own list, where the finish
  is half the point of wanting the thing; any one of the poles named matching is a match, since
  they are one taste rather than a list of requirements.
- **Recipient fit**, a new signal weighted 5 in `for_someone` (surprise went from 20 to 10 to pay
  for this and for occasion) and 0
  in `for_myself`: 1.0 when a recipient or age tag meets the brief's relationship or age band, 0.45
  when the product is tagged for somebody or some age else, 0.5 when nothing was asked or nothing is
  tagged. One signal for both because they answer one question, "is this for them". No text
  fallback: nothing in a title can say a product is for a mother.

`SuggestionEngineTagsTest` pins each of these; `SuggestionEngineDemandTest` still holds, since tags
change nothing about demand.

## The two endpoints

- `GET /api/editorial/products/untagged?market=&limit=&after=` (read): the same editorial-surface
  listing as `/products/untitled` (a Cove, an open plan, a chart, the Surprise pool), filtered to
  products with no tags, each row with its surfaces, its display title and the `vocabulary`.
- `GET /api/editorial/products/to-tag?market=&source=lists|new&days=&limit=&after=` (read): the
  tagging queue, see above.
- `POST /api/editorial/products/tags` (publish) with `{market, tags: [{id, tags: [...], giftable?}]}`, up to
  200, all or nothing. Replaces what a product had, so a wrong tag comes off by leaving it out; an
  empty set clears. A tag outside the vocabulary refuses the batch and names it: a vocabulary that
  grows by typo is not a vocabulary. Tags are stored lower-cased, deduplicated, in vocabulary order.

Publish rather than write for the reason display titles are: the Whisperer reads a tag on the next
request.

## Growing the vocabulary from what people type

The wizard's "anything else?" box accepts any word, and every brief is recorded as a `gift.suggest`
event (append-only, no personal data: the interests and the vibe, never the person). Words that
are not an enum interest are the demand the vocabulary has not met. `GET /interests/candidates`
ranks them per market over the last ninety days, with the vocabulary beside them, so an interest
is added when people keep asking for it rather than when somebody guesses they might (owner's
call, 2026-09-14). It reads the events and not `recipients.interests`: the events are the designed
signal for exactly this question, and a person's saved taste is not to be mined.

Adding an interest stays a code change on purpose: a case on `Interest`, a seed of product nouns
in `AngleMap`, a label in four languages. Each has to be written by a person, and a tag vocabulary
that grew from typed words would grow by typo.

## The whole-catalogue pass (2026-09-28)

Until this date tags covered the editorial surfaces only, about 2,700 products. The owner asked for
every product to carry interests, recipients and occasions, then vibe and preference, and a giftable verdict, from Claude's
own reading of it. Scope, owner's pick: the giftable, untagged groups, about 343,000 across the four
markets; the 111,000 the rules already reject were left alone.

How it runs, and why that way:

- **Judged in a Claude session, posted over the API.** Still no job and no model call on the server
  (invariant 1 holds, and nothing here spends the site's AI budget). The products were read once from
  production, split into files, judged by parallel agents in a workflow, and posted by one script
  through `POST /products/tags` at the write limit. Agents never call the API themselves: the 20
  writes a minute are shared, and forty agents would spend their time on 429s.
- **Five vocabularies, plus the verdict.** Interest, recipient and occasion were the ask; vibe and
  preference were added on the owner's word the same day. Preference only at the poles a product
  clearly sits at (a hand grinder is `manual`, a premium brand `luxurious`), never both ends of a
  pair, and none when the title does not say; a guessed taste scores against the person it misses.
  Age and values stay as they were: a title rarely says either.
- **The brief is widened for recipients and occasions, not dropped.** A recipient or occasion goes
  on where the product plausibly suits it, not only where it is unmistakably for it, since the ask
  was coverage. A product that suits anyone still gets none: recipient fit scores 0.45 for "tagged
  for somebody else" against 0.5 for "untagged", so a recipient tag on everything would quietly
  push every product down for the people it was not tagged for.
- **The giftable verdict goes where the nightly pass cannot undo it**: `giftable_override`, see
  [giftability.md](giftability.md). The pass only ever says `false` there. Every product in scope
  was already giftable by the rules, so `true` would change nothing.

A product that gets no tag at all stays in `gift_tags = '[]'` and still looks untagged. That is
right: nothing was decided about it.

## Tags first: the engine skips the text search where tags can fill the pool (2026-11-15)

Owner's decision, on advice. The engine found candidates two ways at once: the interest's angle
words searched in the text of every offer (a guess), and the interest's tag (a decision). The guess
was the way in when about 2,700 products were tagged. Since the whole-catalogue pass it had become
the expensive half: on production, be-nl "music", the words matched 634,000 offers, the database
read 160,000 pages to check them, and the one query took 39 to 55 seconds, past a web request's 30.
Gift landing pages for music and gaming answered 500 when not cached, and the nightly plan ran out
of its hour. The same interest by tag: 4,559 products in 27 ms.

Now `SuggestionEngine::pool()` leaves the text search out when **every** interest asked has at
least `giftcoves.gift.tags_first_minimum` (300, the candidate pool) tagged products that are
giftable and in stock (`enoughTagged()`, counted to the minimum, kept an hour). The crowd's tags
still count as candidates. Text still runs for an interest with fewer tagged products, for a
free-text interest with no tag, and with the setting at zero.

What it costs: a product that fits an interest and was never tagged for it is no longer a candidate
for that interest. So tagging has to keep up with the catalogue: the tagging queue for new products
(below), and the Cove step (next section).

## Automatic tags: the interest a category implies (2026-11-16)

Owner: "keep tag first and implement automatic tagging where possible". Tags-first assumed the
catalogue was tagged. It was not: the judged tags cover the catalogue of September (about 343,000
products), and on 2026-11-15 the tagging queue held 1.67 million unjudged giftable products in
be-nl alone, 1.55 million in be-fr and 0.8 million in nl-nl, all first seen in the last sixty days.
None of them could be a candidate for an interest that had gone tags-first.

`App\Services\Gift\AutoTagger` fills that from what the judging already says, with no hand-written
list. Per market, a category earns a rule when at least 20 judged products sit in it and at least
60% of them carry the same interest (`giftcoves.auto_tags`); every unjudged, giftable product in
that category then gets that interest, at most two per category. A mixed category clears no bar
and tags nothing. It never reads the category's words, so it works for Dutch, French and English
feeds alike.

- **A column of its own**, `product_groups.auto_tags` (GIN index), never `gift_tags`: a judged tag
  stays what somebody decided. Only products nobody judged (`gift_tags` empty, `gift_tags_at` null);
  a product judged since, or whose category lost its rule, loses its automatic tags on the next run.
- **Interests only.** Recipients and occasions were judged per product; a category says too little.
- **The engine** takes them as candidates, counts them towards "enough tagged" for tags-first, and
  scores the interest at 0.6 (`auto_tags.weight`): under the crowd's 0.75, which is about the very
  product, and under an editor's 1.0. A judged product goes first; inferred ones fill the page.
- **Not read by** the search page's "volgens onze redactie" sentence (`:gift_links`), which claims
  an editor's judgment, nor by the tagging queue: an automatically tagged product is still waiting
  to be judged.
- **Runs** nightly at 04:10 (`bc:auto-tag --write`); `bc:auto-tag` alone counts what would change,
  `--rules` lists the categories. In ranges of 100,000 ids, one short statement each.

Not known until the first production run: how many categories earn a rule per market, and so how
much of the backlog this reaches. nl-nl had few judged products, so it will have few rules.

## Tagged when a Cove is built (2026-11-15)

Picking products for a Cove and tagging them were two jobs, so a Cove could be published full of
untagged products: on this date 1,671 products in published Coves had none. The Cove skill
(`.claude/skills/giftcoves-seed-coves/SKILL.md`, "Tag what you picked") now tags the untagged
products on a shortlist as part of the run, by this brief, and those 1,671 were tagged in one pass.
Owner's call, after the search page started reading the tags (`:gift_links`,
[page-templates.md](page-templates.md)).

## The tagging queue (2026-09-28)

After the whole-catalogue pass, new work arrives two ways: somebody saves a product to a wish list,
or a feed brings a product in. **Catalogue > Product tagging** in the admin counts both per market
and hands out a prompt to paste into a Claude session in this repository. That session runs the
`giftcoves-tag-products` skill (`.claude/skills/`), reads `GET /products/to-tag` and posts through
`POST /products/tags`.

- **A prompt, not a button** (owner's call). The page tags nothing and calls no model, so the
  site's AI budget is untouched and invariant 1 holds without a queued job. The judging is the
  same session-side judgment as the bulk pass, from the same brief: the skill's
  `reference/brief.md` is the text the bulk pass's agents worked from.
- **"Waiting" is `gift_tags_at IS NULL`**, stamped by every tag write. Empty tags cannot say
  "judged": a gift that no tag fits keeps an empty set, and would sit in the queue for ever. The
  migration backfills it from `updated_at` on the products that already carried tags.
- **Two sources.** `lists`: saved to a wish list within the period, whatever the rules said,
  because somebody chose it; only the product comes out, never the list or who saved it. `new`:
  `first_seen_at` within the period and giftable by the rules, the same scope as the bulk pass.
- The page counts both queues for every market on each render: eight counts, about 100 ms each
  warm on production's 450,000 groups (up to a second cold), through the existing
  `(market, first_seen_at)` index. No new index for an admin page.

Files: `app/Services/Gift/TaggingQueue.php`, `app/Filament/Pages/ProductTagging.php`,
`resources/views/filament/pages/product-tagging.blade.php`, `tests/Feature/TaggingQueueTest.php`,
`database/migrations/2026_09_28_001800_product_groups_remember_when_they_were_tagged.php`.

## The tagging brief

Tag what the product *is for*, not everything it could be for. Two or three interests at most, one
occasion only when the product is clearly for it (an advent calendar, a wedding album), a recipient
only when the product is clearly for that person, an age only for products that are for an age.
Vibe and values are cheap and usually clear. When in doubt, leave the tag off: an untagged product
scores neutral, a wrongly tagged one scores against the person it was meant for.

## Files

- `database/migrations/2026_09_14_000200_product_groups_carry_gift_tags.php`,
  `database/migrations/2026_09_14_000300_a_saved_person_remembers_its_taste.php`
- `app/Services/Gift/GiftTags.php`, `app/Enums/RecipientType.php`, `app/Enums/Preference.php`
- `app/Models/ProductGroup.php` (`giftTags()`), `app/Services/Gift/SuggestionEngine.php`
- `app/Services/Gift/InterestCandidates.php`, `app/Http/Controllers/Api/InterestCandidatesController.php`
- `app/Services/Editorial/UntitledProducts.php`, `app/Http/Controllers/Api/ProductTitleController.php`
- `config/giftcoves.php` (`recipient_fit`)
- `tests/Feature/GiftTagsApiTest.php`, `tests/Feature/SuggestionEngineTagsTest.php`
