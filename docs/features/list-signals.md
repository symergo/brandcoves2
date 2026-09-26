---
name: List signals (what lists teach the catalogue)
area: Gifting / Catalogue
status: Active — counted nightly (03:50); nothing shows until enough different people agree
date_added: 2026-09-26
---

# List signals: what people's lists teach the catalogue

Roadmap step 4, engine F and G ([../strategy.md](../strategy.md)). The owner's ideas, all from
2026-09-26:

- a list made with an intent says "these things fit that", so its products carry that intent for
  everybody;
- the intent is deduced from the wish lists and gift lists themselves;
- products on the same list share it, and are linked to each other;
- a person's own wish list is the brief for a gift for them.

## Crowd tags

`CountListSignals` (nightly, 03:50, after the personal-data prune) deduces each list's intent from
everything the list says about itself:

| Where | Becomes |
|---|---|
| the recipient's relationship, age band, interests | `recipient:father`, `age:30-49`, `interest:cooking` |
| the list's occasion (`event_type`) | `occasion:christmas` |
| the list's title and description, read by `GiftIntentParser` as a gift | "Kerst voor oma" → `occasion:christmas`, `recipient:grandparent` |
| editors' interest tags shared by two or more products on the list | two cooking products make it a cooking list |

A product earns a tag when **at least 5 different people's** lists carrying it hold the product
(`giftcoves.list_signals.min_owners`). Tags land in `product_groups.crowd_tags`, never in the
editors' `gift_tags`. The suggestion engine reads them at **0.75** of an editor's tag
(`list_signals.weight`): it finds products by them and scores interest, occasion and recipient fit
with them. A crowd tag for somebody else is never evidence against, only a lift.

## Product links

Products on the same lists, wish lists and gift lists alike, counted in different people, from 5,
within one market (invariant 2): `product_links`. The product page shows the top four as **Often on
the same lists** ([product-signals.md](product-signals.md)).

## Crowd picks

The same count also writes `crowd_picks`: per product, the kinds of person (one fact or a pair,
"a father who likes cooking") that five or more people shopping for keep it on a list. The Gift
Finder and This or that use it to rank and label "chosen by others for someone like them". See
[crowd-picks.md](crowd-picks.md).

## A wish list as a brief

`TasteBrief::fromList()`: the three interests the list's products are most tagged with (editors' and
crowd tags), a budget from half to one and a half times the median price on it, the list's
occasion, and everything on it excluded. The shared page of a person's own wish list shows four
**ideas in the same spirit** to the people it is shared with (never to the owner), cached an hour
per list version. No brief, no block: a list with no tagged product says too little.

Until the clean-up of 2026-09-26 the block's heading and hint showed as raw keys
(`lists.like_this_title`): a merge had put the two strings under `gift_cove` in all four languages.
They live under `lists` now.

## The rules

- **People, not lists.** Every count is distinct owners (a user or an anonymous identity). One
  person with ten lists is one.
- **A threshold** before anything counts, so no single person, or a few friends, moves anything.
- **Counts only.** No list, owner, name or text leaves the list.
- **Private lists count too** (the owner's decision). The privacy page says so, in "What lists
  teach together".
- **Claims are never read** (invariant 4), and unaccepted suggestions do not count.
- **The crowd cannot feed on itself**: only editors' tags are read back into a list's intent.
- **No AI**: word lists and SQL.

## Offline items

Items typed by hand, with no product behind them, cannot earn a tag or a link. What they can
teach, ideas nobody sells here, is counted separately and needs a person's approval before it
shows: [offline-ideas.md](offline-ideas.md).

## Not yet

The intent of the search a product was saved from is not recorded with the save; a list's own
intent covers most of it. It is the next piece if the counts turn out thin.

## Tests

`ListSignalsTest`: the threshold in people, titles, shared product tags, unaccepted suggestions,
links within one market, the engine finding a product by a crowd tag, a wish list as a brief
(visitor sees ideas, owner does not).
