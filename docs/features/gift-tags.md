---
name: Gift tags
area: Catalogue / Gifting / Editorial
status: Active
date_added: 2026-09-14
---

# Gift tags

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
| `interest` | the thirty-six wizard interests (twenty broad ones, sixteen hobbies added 2026-09-14: art, cycling, board games, drinks, baking, running, yoga, cars, science, water sports, winter sports, football, collecting, nature, fishing, horses) | `App\Enums\Interest` |
| `occasion` | the list occasions except `other`, plus sinterklaas, easter, new_year, halloween, communion, christening, engagement, get_well, new_job, secret_santa | `App\Enums\EventType`, `GiftTags::EXTRA_OCCASIONS` |
| `recipient` | partner, mother, father, grandparent, child, friend, colleague, sibling, teacher, host | `App\Enums\RecipientType` |
| `age` | 0-2, 3-5, 6-9, 10-12, 13-17, 18-29, 30-49, 50-64, 65+ | `GiftTags::AGE_BANDS` |
| `vibe` | practical, playful, beautiful | `App\Enums\Vibe` |
| `values` | sustainable, local, handmade | `GiftTags::VALUE_OPTIONS` |

A tag is `<vocabulary>:<value>`. The whole list is `GiftTags::all()`, and `GET /products/untagged`
returns it beside the products, so a writer never has to guess a spelling.

## Two decisions the owner asked about (2026-09-14)

**No gender.** A friend or a colleague has no gender in the vocabulary, on purpose. "Mother" and
"father" are relationships. A product an editor would tag "for women" is a stereotype more often
than a fact, and where a product genuinely is gendered (a razor, a dress) its title says so and
search finds it. If a market ever needs it, it would be a separate `audience` vocabulary, not a
split of `recipient`.

**Age is its own vocabulary, as fixed groups of years.** The bands are ranges (owner's call: real
age groups, fixed on both sides, nothing typed): 0-2, 3-5, 6-9, 10-12, 13-17, 18-29, 30-49, 50-64,
65+, cut where presents change. An editor tags the range a product suits, and the wizard asks the
giver to pick one of the same ranges as a step ("How old are they?"), so the two meet as the same
string and nothing has to be folded. An age band stored on a person from before the groups existed
matches nothing and scores neutral. Splitting age from `recipient` is what lets `recipient:child`
mean "their child, who may be forty".

## Storage and retrieval

`product_groups.gift_tags`, a jsonb array, empty by default, with a GIN index. Untagged is the
empty array, which is nearly every row. Nothing automated writes it: not the grouper, not a job,
not a model. The engine asks `jsonb_exists_any(gift_tags, ARRAY[...])`, spelled as the function
because a bare `?` is a placeholder to PDO, and the default jsonb operator class indexes it.

## How the Whisperer reads them

- **Retrieval**: the candidate pool is the text match OR any interest tag from the brief, both
  indexed. A tagged product is found whether or not its title agrees.
- **Interest fit**: a tag on an interest is a match at full strength on that interest's slot,
  ahead of any text strength. `interest:coffee` on the product and "coffee" first in the brief is
  the strongest evidence the engine gets. The signal is vectorial (owner's call, 2026-09-14): half
  the best single interest answered, half the weighted share of the whole brief answered, so a
  product tagged for three of four interests beats one tagged for the first alone. The arithmetic
  is on `SuggestionEngine::interestFit()` and in gift-whisperer.md.
- **Occasion, vibe, values**: the tag answers before the title words are looked for. Occasion
  weighs 5 now, from zero: title words were too thin to trust, an editor's tag is not.
- **Recipient fit**, a new signal weighted 5 in `for_someone` (taken from surprise, 20 to 15) and 0
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
- `POST /api/editorial/products/tags` (publish) with `{market, tags: [{id, tags: [...]}]}`, up to
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

## The tagging brief

Tag what the product *is for*, not everything it could be for. Two or three interests at most, one
occasion only when the product is clearly for it (an advent calendar, a wedding album), a recipient
only when the product is clearly for that person, an age only for products that are for an age.
Vibe and values are cheap and usually clear. When in doubt, leave the tag off: an untagged product
scores neutral, a wrongly tagged one scores against the person it was meant for.

## Files

- `database/migrations/2026_09_14_000200_product_groups_carry_gift_tags.php`
- `app/Services/Gift/GiftTags.php`, `app/Enums/RecipientType.php`
- `app/Models/ProductGroup.php` (`giftTags()`), `app/Services/Gift/SuggestionEngine.php`
- `app/Services/Editorial/UntitledProducts.php`, `app/Http/Controllers/Api/ProductTitleController.php`
- `config/giftcoves.php` (`recipient_fit`)
- `tests/Feature/GiftTagsApiTest.php`, `tests/Feature/SuggestionEngineTagsTest.php`
