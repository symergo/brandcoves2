---
name: Display titles
area: Catalogue / Editorial
status: Active
date_added: 2026-09-14
---

# Display titles

A hand-written, gift-friendly product title, stored beside the feed's title and shown wherever a
visitor reads one.

## Why

Product titles are the merchant's feed strings: ALL CAPS, warehouse codes, 121 characters in the
median on `en`. "HYPER X ORIGINS 2 PRO 65 AZERTY FR" on the front page makes a gift site read like an
electronics feed, and the mechanical cleaner (`ProductTitle::heading()`, which undoes the shouting
and puts the brand in front) can only go so far: it cannot know that a product is a present, or say
why. The owner asked (2026-09-13) for titles that read as gifts, with the original kept for search.

## Why a sibling column, not a rewrite

Three things depend on `product_groups.title` being exactly what the feed sent:

- Relevance ordering sorts on it (`SearchService::orderByRelevance()`, `word_similarity()` against
  the group title), and the offers' search vector is built from the offers' titles.
- The grouper rewrites it from the best offer on every run (`ProductGrouper`, the `display` CTE), so
  an in-place rewrite would be undone twice a day.
- The slug and the identity key derive from it.

So `display_title` is a nullable column that nothing automated writes. Two accessors read it.
`ProductGroup::displayTitle()` returns it, or the feed title with the shouting undone
(`ProductTitle::card()`), and every card, tile, row and mail goes through it: search cards, Cove
finds, Today's Cove, Surprise, brand pages, rails, the scanner, Ask, Secret Friend, list matches,
notifications, the quiz. `ProductGroup::heading()` returns it, or `ProductTitle::heading()`, which
also puts the brand in front, for the three places the string stands alone with no brand label
beside it: the product page `<h1>`, the JSON-LD name and the social card. The split exists because
a card already names the brand next to the title, and prefixing it there produced "Sony Sony". `DisplayTitleTest` pins the split from both sides: a search on
a code that only the feed title carries still finds the product, and shows it under the written
title; a regrouping run leaves the written title alone.

What still reads the raw title, on purpose: search and ranking, the grouper, `IdentityResolver`,
term extraction (`ResultTerms`), the two scorers (`SuggestionEngine`, `SerendipityEngine`), and
every prompt the builders assemble (`CovePrompt`, `EditionBuilder`, `GuideWriter`,
`ClassifyGiftability`). The editorial lookup (`ProductLookup::describe()`) carries both `title` and
`displayTitle`: an author matching a product needs the feed's words.

`wishlist_items.snapshot_title` is a write, not a render. New saves snapshot the display title;
existing rows are left as they are. A snapshot is a record of what the person saw when they chose,
and it is editable by them, so a backfill would overwrite decisions.

## Search finds both titles

Owner's call: a visitor who reads "Hario handmolen" on a card and types it into the box must find
the product, and the feed title says "KOFFIEMOLEN HANDMATIG". So the written title is searched
exactly the way the feed title is, by the same two mechanisms: a generated tsvector on the group
(`display_vector`, stemmed per market through the same immutable helpers) with a GIN index, and a
trigram index on `display_title` for typos. `SearchService::applyTextMatch()` adds them as two more
branches of its union of ids, each answerable by an index, which is the whole reason that query is
a union and not three ORed clauses (see search.md). Relevance ordering takes the better of the two
word similarities, so a product found on its written title ranks as if that were its title.

## No model runs in the application for this

The obvious design, a nightly job that asks the model for titles, was rejected by the owner: no AI
API spend for this. The titles are authored the way Coves are, from a session, and posted over the
editorial API. The application only stores and shows them. That is also why there is no rule-based
"gift rewrite" beyond the existing cleaner: a rule that strips trailing model codes would strip
"WH-1000XM5", which is the product's name and what people search for.

## The two endpoints

- `GET /api/editorial/products/untitled?market=&limit=&after=` (read ability). Products in the
  market with no display title that sit on an editorial surface: a pick in a published or scheduled
  edition of any kind (`daily`), an item on an open plan (`plan`), a bestseller chart captured in the
  last fortnight (`chart`), or the top 200 of the Surprise pool, the sampler's own pool (`surprise`).
  Each row names its surfaces, so the writer knows why it is there. Paged by id with `after`, since
  the writer posts titles as they go and a page that moved under them would repeat products. Search
  results are not a surface here: a search shows what was searched for, and the cleaner is enough.
- `POST /api/editorial/products/titles` (publish ability) with `{market, titles: [{id, title}]}`,
  up to 200. All or nothing: one id outside the market refuses the batch and names it, on the same
  reasoning as `ProductLookup::rejectUnusable()`. A title is 3 to 80 characters after
  `HouseStyle::plain()` (em dashes to spaced hyphens, bold markers off: a title is a text node).
  `null` clears one, so a bad title can be withdrawn without inventing a better one.

Publish, not write: the route groups promise that nothing under `write` reaches a reader, and a
display title reaches every reader on the next request.

## The writing brief

Per market, in the market's language. Sixty characters or fewer. Brand first when it is a name
people know. Say what the thing is and the one quality that makes it a present. Keep model names
people search for (Tune 530BT, WH-1000XM5); drop warehouse codes (MD340101), sizes and colour
variants unless they are the point. No capitals for shouting, no prices, no exclamation marks, no em
dashes. Never change what the product is: a title that promises a feature the product lacks is a
refund. Review each batch with the feed title beside the draft before posting.

The drafting may go to a cheaper model than the one running the session; the review stays with the
session. Post 200 per request at the write rate (20 a minute).

## Sibling: gift tags

The same products get gift tags from a closed vocabulary over the sibling endpoints; see
[gift-tags.md](gift-tags.md).

## Files

- `database/migrations/2026_09_14_000100_product_groups_carry_a_display_title.php`
- `app/Models/ProductGroup.php` (`displayTitle()`), `app/Services/Catalogue/ProductTitle.php`
- `app/Services/Editorial/UntitledProducts.php`, `app/Http/Controllers/Api/ProductTitleController.php`
- `routes/api.php`, `app/Http/Controllers/Api/EditorialIndexController.php`
- `tests/Feature/DisplayTitleTest.php`, `tests/Feature/ProductTitlesApiTest.php`
