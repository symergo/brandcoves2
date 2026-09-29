---
name: This or that (taste discovery)
area: Gifting
status: Active
date_added: 2026-09-26
---

# This or that: taste discovery by choosing

The owner's request (2026-09-26): "a taste discovery tool by swiping or selecting random products and
building a profile of a person based on the selections made (e.g. choose between two, or
like/dislike)".

A dozen rounds of real products, two at a time ("which would they rather get?"). Until 2026-09-28
every fourth round was a single card to like, dislike or swipe; swiping became a way of its own,
[swipe-gifts.md](swipe-gifts.md), and the owner asked for it out of here. From the choices, with no AI, we work out the person's
interests, a price band, interests to leave out, and where the tags allow it a vibe, taste poles and
values. Then eight ideas from the suggestion engine, and the result can be kept on a person so the
Find a gift starts from it next time.

Why it exists: Find a gift asks "what are they into?", and a giver who does not know is stuck on
the first question. Recognising something is easier than naming it. The same goes for the person
themselves on their own page: picking between two things is quicker than filling in a form about
yourself.

## Where it is

| | |
|---|---|
| Page | `/{market}/gift/taste` (a giver, or yourself), `/{market}/for/{token}/taste` (the person themselves) |
| Doors | Find a gift's interests step ("Not sure what they are into?"), the "Or show us by choosing" button on `/for/{token}`, the help page |
| Controller | `App\Http\Controllers\TasteController` |
| Services | `App\Services\Gift\TasteDeck` (which rounds), `TasteProfiler` (choices to a profile), `TasteProfile`, `TasteCard`, `TasteChoice`, `TasteChoiceReader` |
| Page | `resources/js/Pages/Gift/Taste.tsx`; icon `taste` in `ToolIcon` |
| Copy | `site.gift.taste.*`, `site.recipients.taste_link*`, `site.help.find_taste`, in four languages |
| Tests | `tests/Unit/TasteProfilerTest.php`, `tests/Unit/TasteDeckTest.php`, `tests/Feature/TasteDiscoveryTest.php` |

## How rounds are chosen (TasteDeck)

Nobody gives a quiz more than about a dozen rounds, so each round has to teach something.

- **The two sides of a pair never share an interest tag.** A coffee grinder against a coffee cup
  teaches nothing about interests; against a yoga mat it teaches one thing clearly.
- **Four rounds explore, the rest focus.** Exploring shows the interests seen least so far, so the
  first three pairs cover about six interests. Focusing puts the current favourite back on the table,
  against something new or, every other round, against the second favourite, so a favourite has to
  win more than once to count.
- **Exploring pairs differ in price** (one 1.5 to 4 times the other), so what was picked also tells
  us about the price band. **Focusing pairs sit within 1.6 times** of each other, so the choice is
  about the interest and not the price.
- **Every round is a pair.** A card left with no partner is not shown. The single card that used to
  come every fourth round (it re-tested an interest passed over) went with the swiping, above; the
  reader still accepts a single-card answer so a session open during that deploy could finish.
- **Random, within the market.** Each request draws 160 presentable, giftable products of this
  market carrying an interest tag (an editor's `gift_tags` or the crowd's `crowd_tags`) in random
  order, and the pool's order breaks every tie. Two sessions never see the same deck. A market with
  too few tagged products is topped up with untagged ones: they teach only the price, but a page
  that shows nothing is worse. (Local development data has no tags, so there it is a price-only
  quiz.)
- **Drawn from a cached pool (2026-09-27).** Each request used to sort every giftable product of
  the market at random twice, once through a `gift_tags::text like '%"interest:%'` that no index
  can serve, and load whole rows for all ~240 of them. Now `TasteDeck::pool()` keeps, per market
  and for ten minutes, up to 1,500 tagged products (all of them, at production's ~700) and a random
  2,500 of the rest, as plain rows of id, price, tags and the interests read from the title, already
  filtered to what can teach something. A request samples its 160 from that in PHP with the same
  shares (proven gifts, tagged up to half, then the rest), builds each card once, and loads only the
  products it shows, with the columns a card prints. The tagged read uses the `?|` operator, which
  the tag GIN indexes serve. A product that went out of stock since the pool was drawn is dropped
  with its round. Plain arrays, not models, because the cache refuses to rebuild objects.
- **The page asks for the next four rounds while two are still left**, sending the choices so far
  and every id on screen or queued, so the next pair is ready and nothing repeats. The rules above
  are relaxed one at a time when the pool is thin (rival, then price, then shared interest), so a
  small market still gets pairs.

## How choices become a profile (TasteProfiler)

Pure arithmetic over the tags of the cards, from the catalogue:

| What happened to the card | Score per tag on it |
|---|---|
| picked from a pair | +1 |
| liked on its own | +1 |
| passed over in a pair | -0.5 |
| disliked on its own | -1 |

times 1 for an editor's tag and 0.75 for a crowd tag (the weight the suggestion engine gives crowd
tags, `giftcoves.list_signals.weight`, so both tools weigh the same evidence the same way).

- **Passing over is half a dislike.** Picking the grinder over the yoga mat says "rather this", not
  "never that". Somebody who loves both has to pick one, and scoring the loser at -1 would put half
  their real interests on the avoid list.
- **A tag both cards share is ignored.** It cannot be what the choice was about.
- **An interest needs two good rounds** (net 1.5), because one pick is often just the nicer photo. At
  most four interests, strongest first. After a short session where nothing reaches that, the best
  one or two with a clean pick are named, and the page says it is a thin reading (under four rounds
  answered).
- **Avoid needs two separate bad rounds and no good one**, at most three. Avoid is a hard filter in
  the engine: one wrong entry hides a whole shelf, so a single dislike is never enough, and an
  interest that was ever picked is never avoided.
- **The price band** is the lower to upper quartile of what was picked or liked, widened by a fifth
  each way, rounded to five euros and kept inside the gift engine's window (5 to 500 euro). From
  three prices up: two prices are an anecdote.
- **Vibe** only when one clearly leads; **taste poles** at most three, never both ends of one axis;
  **values** likewise, all at the same 1.5 bar.
- Skips teach nothing. They are sent only so the same cards are not shown again.

## An avoided interest travels as `interest:gaming`

`recipients.avoid` and `TasteBrief::$avoid` hold words that are matched against titles with ILIKE.
An interest key cannot go in as a plain word: "art" would remove every title containing "smart" or
"party", and "gaming" matches almost no Dutch title. So an avoided interest is written in the tag's
own spelling, `interest:gaming`, and the engine excludes products *tagged* with it (editors' or
crowd tags) and never matches it against a title (`TasteBrief::avoidWords()` and
`avoidedInterests()`, `SuggestionEngine::pool()`). Nobody types `interest:` by hand, so words people
wrote behave exactly as before. Find a gift shows such entries by the interest's name and
removes them with a tap rather than through the word box.

## Nothing is stored while choosing

The rounds and choices live in the page. Every request carries the choices so far as product ids
and what was pressed; the tags and prices are read from the catalogue by `TasteChoiceReader`, within
the market (invariant 2). A request can say "I picked 41", never "41 is a cooking present". An id from
another market is dropped with its round, a pick that was not one of the shown cards is a skip, and
saving recomputes the profile from the choices rather than accepting one. So there is no table, no
retention window to add to `bc:prune-personal-data`, and nothing new for the privacy page. The one
thing recorded is a `gift.taste` event with the market, the number of rounds answered and the
interests found, no person, like `gift.suggest`.

## Keeping the result

- **On one of your people, or somebody new** (`POST /gift/taste/save`), the way Find a gift's
  "remember" writes: the taste through `describeTaste()` as a guess, which is refused when the person
  described their own taste through their link, and the price band directly, because what you spend
  is your fact and not their taste. Either way it **adds** to what is stored rather than replacing it:
  today's interests first, the stored ones after, and only contradictions removed (an interest learned
  today comes off the avoid list and the other way round). Making a new person needs an account, as
  everywhere else since 2026-09-06; anonymous visitors can use the whole tool and are offered sign-in
  to keep the result.

  The "Bewaar voor [naam]" buttons stayed as they are when the rest of the site moved to one Save
  button on 2026-09-26 ([save-button.md](save-button.md)): they keep a *taste*, not a thing, and have
  no saved state to fill in, so the bookmark icon would promise something they cannot show. They
  already use the same verb.

  **As people cards since 2026-09-27** (consistency review, round 3; see
  [design-system.md](design-system.md)): the row of buttons became `PersonPicker`'s compact cards,
  the ones Find a gift and the list wizard draw, so a person looks the same wherever they are
  chosen. A press still saves at once (the card's spoken name ends in "Bewaar voor [naam]"). Signed
  in, the cards are My people's rows, sent with the result only (`TasteController`, the rounds page
  does not pay for the query), which brings two changes: **friends on GiftCoves are offered too**,
  and a "self" person (This or that played "for me" before) is no longer listed, as My people does
  not list it. A friend nobody saved is saved by the same request (`friend_id`): the linked person
  `POST /recipients` with `friend_id` makes, reused the second time, and only for an actual friend
  (anything else is a 404). Signed out, the saved people are the same cards with the name only.
- **Choosing for yourself** ("Me") ranks the ideas as for yourself (`SuggestionProfile::forMyself`)
  and offers each one to your wish list. There is nowhere to store a taste on an account, and a
  person's own wish list already acts as their brief (`TasteBrief::fromList`, list-signals.md), so
  adding a "my taste" store was not worth it yet.
- **The person themselves** at `/for/{token}/taste`: the token is the whole authorisation, as on the
  rest of that page. Their choices are saved as their own answer (`TasteSource::Self`), so the giver's
  guesses no longer overwrite them. They are merged with what they said before only when *they* said
  it: merging into the giver's guesses and stamping the lot "self" would pass a guess off as their
  answer. The budget is never written from there; it is the giver's. Nothing of the giver's is sent
  to that page (no people, no notes).

## Interaction

**The rounds play in a popup** since 2026-09-29 (owner: "same layout for Dit of dat" as Swipe
gifts, `Components/PlayDialog.tsx`): the full screen on a phone, a wide panel over a dimmed page
from `sm` up, with no scrolling. The round and who it is for sit in the top bar, the close button
goes back to where it was opened from. On a phone the pair sits one above the other, since side by
side each picture was a third of the screen's width; from `sm` up they are side by side. Each
picture takes what the title and price leave, from the image proxy (`imageToken`). "Skip" and "Show
the result" are two buttons under the pair. The result is an ordinary page again.

Tap or click a card, or use the arrow keys (left and right choose, down skips). The progress bar
transition is off for reduced motion. "Show the result" is offered after three answered rounds.

## No AI

A random draw, a few pairing rules and arithmetic. The feature test mocks `AiClient` and asserts it
is never called on any of these routes (invariant 1).

## Learning from every product, not only tagged ones (2026-09-26)

The owner found the tool "not very adapted", and it wasn't: it learns from interest tags, and the
local catalogue had none while production had some 700 per market out of about 150,000 giftable
products. So twelve rounds taught it a price band and little else, and the pairs were random
`giftable` products: a cooker-hood part against a phone case, a blood-pressure meter, a kettle.

- **`InterestGuesser`** reads a product's own title and category against word lists per interest
  (`resources/content/interest-words.php`, every language in one list because eBay's categories
  arrive in English, German or Italian on any market). A guess counts at **0.75**, the crowd
  tag's weight: at 0.5 a guessed interest needed three good rounds, which twelve rounds over forty
  interests rarely give, and the tool went back to learning nothing. A tag on the same interest
  overrides the guess. Measured on a production copy: 51 to 73% of giftable products get an
  interest this way.
- **Not gifts are left out** of the pairs: parts, refills, cases, cables, household supplies (10 to
  19% of `giftable`), by the same file's `not_gifts` list. So is any product with no interest at
  all, which could teach only a price.
- **The pool is at most half tagged products.** Tagged-only pools were the same 700 items, heavy on
  fitness and wellness, and showed one music product in twelve rounds.
- **Among equally fresh cards, the one touching more interests wins**, so each round covers more.

Simulated on the production copy (a person who picks their interest whenever it is shown, at
random otherwise): the right interest was learned in 6 of 10 runs, from close to none. The rest is
coverage: twelve rounds of two cannot show forty interests.

## A way in from the Cadeauzoeker (2026-09-26)

The owner asked for This or that in Find a gift itself. A banner above the first question offers
it ("Liever kiezen dan vragen beantwoorden? Speel Dit of dat") for somebody who cannot say what the
person likes. It shows only before anything is answered; once somebody is into the questions it
would distract. The small link on the interests step stays, and is hidden when the banner already
shows on the same screen (signed out, the interests step is the first).

Superseded later that day by [find-a-gift.md](find-a-gift.md): This or that is the second of three
ways after "Who is it for?", and the banner is gone.

## One of the three ways of Find a gift, ending on its results (2026-09-26)

- **Who it is for arrives in the link**: `?person=<id>` (one of your own people, else ignored) or
  `?relationship=mother` (the vocabulary, else ignored). The server sends it back as `carried`, the
  page skips "for someone or for yourself?", shows "For Mum · change", and posts it with the result.
  `?person=` was read by the page before; the server reads it now, so a stranger's id never reaches
  the page.
- **The kind of person reaches the brief** (`TasteProfile::brief(relationship:)`), so an editor's
  `recipient:` tag scores and Coves others made for the same kind of person match.
- **The result is the one results page** (`GiftResults`): the same cards, and for a giver also
  *Open as a page*, Coves others made, the next steps and list of a saved person, and *Ask other
  people*. *Refine with the questions* posts what was learned (`result.refine`) to the questions.
  The person's own page and a shared "help me find out" link still get the ideas only.

## Proven gifts in the deck, and labelled ideas (2026-09-26)

A tenth of each draw (16 of 160) is kept for products at least five different people keep on a list
for somebody, drawn at random from the market's 200 most kept, counted against the tagged half. They
pass the same price window and "is this a gift" test, and the pairing rules treat them like any other
card. The ideas at the end come from the suggestion engine, so a product people shopping for someone
with the same interests picked ranks higher there and carries the line "Chosen by others for someone
like them" (or "with the same interests" for yourself). Until five people agree on anything, which
on production is still the case, nothing of this shows. See [crowd-picks.md](crowd-picks.md).

## Played together, and shared as a card (2026-09-26)

Two things now store something, each by choice and each in its own table, so the rule above
("nothing is stored while choosing") still holds for the tool itself:

- **Together**: a giver makes a link for one of their people and several people play about them;
  each finished run's choices are kept, and the combined profile is worked out from all of them.
  See [taste-together.md](taste-together.md).
- **Gift profile card**: after choosing for yourself ("Me", or the person's own
  `/for/{token}/taste`), "Make my card" keeps the profile (never the choices) behind a link that
  opens Find a gift filled in. See [gift-profile-card.md](gift-profile-card.md).

Both have retention windows in `bc:prune-personal-data` and a line on the privacy page.
`TasteController::outcome()`, `firstRounds()` and `choiceRules()` are `protected` so
`TasteTogetherController` can reuse them.
