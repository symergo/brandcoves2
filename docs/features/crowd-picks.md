---
name: Chosen by others for someone like them (crowd picks)
area: Gifting
status: Active — counted nightly with the list signals; shows nothing until five different people agree, which on day one is nowhere
date_added: 2026-09-27
---

# Chosen by others for someone like them

The owner's request (2026-09-26): "Use the gifts created by others as suggestions for others." This
part: products that sit on other people's lists become suggestions for somebody shopping for a
similar person. "People shopping for a dad who likes cooking also picked this."

## What it does

- **Gift Finder** (`/gift`) and **This or that** results: a product that enough people shopping for
  the same kind of person keep on their lists ranks higher, and its card says so in one small line:
  "Chosen by others for someone like them" (NL "Gekozen door anderen voor iemand zoals die persoon").
  On This or that for yourself it reads "Chosen by others with the same interests".
- Such a product reaches the results **even when nothing in its title or tags matches the brief**:
  the engine adds up to 40 of them to its candidates, through the same filters as everything else
  (budget, avoid, excluded, giftable), and they then compete on the same score.
- **This or that's deck** keeps a tenth of its random draw (16 of 160) for proven gifts, products at
  least five people keep on a list for somebody. They are still random, still pass the deck's price
  window and "is this a gift" test, and still follow every pairing rule. A proven gift is a real
  present, so a round with one teaches more.
- The help page has a line on it, in four languages (`site.help.find_crowd`).

## A kind of person is one fact or two

A list's context is what `CountListSignals` already deduces about it (list-signals.md): the
recipient's relationship, age band and interests, the list's occasion, what its title and
description say, and interests the editors' tags give two or more of its products. For crowd picks,
and only here, interests the **crowd's** tags give two or more products count too, the way
`TasteBrief::fromList()` reads a list.

Every single fact and every **pair** of facts is a context: `recipient:father`, `interest:cooking`,
`interest:cooking+recipient:father`. A product gets a row in `crowd_picks` for a context once at
least five different people's lists with that context hold it.

**Why pairs.** Crowd tags count each fact on its own. Five people shopping for a father and five
others shopping for somebody who cooks give a product both tags, and not one of them was shopping
for a father who cooks. Only a pair counted on the same lists says that. **Why no triples:** five
people who all shopped for a father of fifty who cooks, for his birthday, is nobody at today's
numbers, and a pair already says what a tag cannot.

## Scoring (`CrowdPickMatcher`, pure)

- The brief's facts: a relationship the site knows (`RecipientType`), an age band, up to four
  interests from the wizard's list, an occasion from the tag vocabulary. Free text ("oude motoren",
  "my mum") matches nothing, because lists are counted in the same closed vocabulary.
- Per product, the best matching context decides: a pair is worth 1.0, a single fact 0.6, times how
  sure the count is, 0.7 at five people rising on a log scale to 1.0 at twenty.
- The engine adds `crowd` points: strength × **10** for someone else (as much as vibe), × **5** on
  your own list (`giftcoves.gift.profiles.*.weights.crowd`). Only ever a lift: no count is not
  evidence against, since most good presents are on nobody's list yet.
- **The label needs a person.** A match on an occasion alone ("people picked this for Christmas")
  lifts the product but puts no line on the card: it would promise a match with the person on the
  strength of the calendar.

## Privacy: five people is the guarantee

- `giftcoves.list_signals.min_owners` (5), the same setting as crowd tags, product links and "saved
  by N". Below five, "chosen for a father who likes cooking" could point at one person's list. The
  config comment says never to lower it to fill a page.
- The threshold is applied twice: in the nightly count, and again when a row is read
  (`CrowdPickMatcher::score()` drops anything under it), so a row written under another setting or
  by hand cannot show.
- People, not lists: one person with seven lists is one. Only counts are stored: a context, a
  product and a number. No list, owner or word anybody wrote.
- Private lists count (the owner's decision, list-signals.md). The privacy page already says lists
  count towards "who they are often chosen for"; this is that, so the page did not change.
- Claims are never read (invariant 4); only accepted items count.
- One market (invariant 2): a list counts only for products of its own market, and a brief looks
  only at its own market's rows.

## Thin data is no data

Production held about 13 lists when this was built. Nothing reaches five people, so on day one the
table is empty and every lookup returns nothing: no boost, no label, no extra candidates, no error.
That is the design. It starts to show on its own as lists grow, beginning with the broadest
contexts (a single relationship or interest) and reaching pairs later.

## Cost per request

One read of `crowd_picks` by its primary key (`market`, `context` in the brief's contexts, at most
400 rows), cached until the next count: the job bumps a version key (`CrowdPicks::forgetCached()`),
so tonight's numbers are never hidden behind yesterday's cache. The deck's proven gifts are one
cached query per market. No AI anywhere.

## Where it is

| | |
|---|---|
| Count | `App\Jobs\CountListSignals::writeCrowdPicks()`, nightly 03:50 |
| Table | `crowd_picks` (`market`, `context`, `group_id`, `owners`), migration `2026_09_27_000200_people_shopping_for_someone_similar` |
| Lookup | `App\Services\Gift\CrowdPicks` (`forBrief`, `provenGifts`) |
| Rules | `App\Services\Gift\CrowdPickMatcher`, `CrowdPick` |
| Engine | `SuggestionEngine::withCrowdPicks()`, the `crowd` signal, `Suggestion::chosenByOthers()` |
| Deck | `TasteDeck::proven()` |
| Pages | `Components/GiftResults.tsx` (the `chosenByOthers` line), drawn by `Gift/Wizard.tsx`, `Gift/Taste.tsx` and the gift landing pages since 2026-09-26 ([find-a-gift.md](find-a-gift.md)); `Help.tsx` |
| Copy | `site.gift.chosen_by_others`, `site.gift.chosen_by_others_me`, `site.help.find_crowd` |
| Tests | `tests/Unit/CrowdPickMatcherTest.php`, `tests/Feature/CrowdPicksTest.php` |

## Also fixed on the way

`CountListSignals` created its `earned_tags` temporary table `ON COMMIT DROP` inside a transaction.
Run inside an outer transaction (every test, or a second run on one connection), that transaction is
only a savepoint, the table survived, and a second count collided with the first's rows. It is now
emptied after it is created.

## Not yet

- The count does not know the budget a list was made with, so the brief's budget acts only as the
  engine's usual filter, not as part of "someone like them".
- The label does not say *which* kind of person ("for a dad who likes cooking"). The generic line
  was chosen to keep the card small; the facts are on `CrowdPick::$tags` if the owner wants them
  named.

## Temporarily one person (2026-09-26)

The owner lowered the bar to one person for now, while production has too few lists (13) for
anything to reach five. It is `GIFT_MIN_OWNERS=1` in the environment, read by both
`giftcoves.list_signals.min_owners` and `giftcoves.offline_ideas.min_owners`; the code's default
stays 5, and the tests pin 5 in `phpunit.xml`. While it is 1, the privacy page no longer names a
number: it still promises only numbers, no list, name or own words shown, and that a person reads
and rewrites every offline idea. **To restore:** remove `GIFT_MIN_OWNERS` everywhere (local `.env`,
both Coolify apps) and put back the privacy page sentences about "enough different people" and
"at least five different people" (en and nl), from git history before this date.
