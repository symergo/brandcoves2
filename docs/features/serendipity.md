---
name: The Serendipity Engine
area: Discovery
status: Active
date_added: 2026-08-08
---

# The Serendipity Engine

Scores how likely a product is to make someone say *"I didn't know that existed."*

It is the shared machinery behind three surfaces: the `/surprise` page, the gift engine's `surprise`
signal, and Daily Picks. All three read the same `product_groups.surprise_score`.

## The idea

A retailer ranks for what everyone buys. By definition you have already seen it. This ranks for the
opposite, on the theory that the only thing a comparison site can offer that a shop cannot is the
stuff the shop's own merchandising buries.

## The failure this is built around

> **Serendipity and junk are numerically identical if you only measure rarity.**

A no-name phone case, stocked by one merchant, in a category of one, with no image, is *perfect* on
every rarity signal. It is also the worst possible thing to put on a page whose promise is "look what
we found".

So the score is a **product, not a sum**:

```
score = rarity × worthSeeing
```

A product has to earn both. Something nobody else sells *and* that looks good *and* that a real shop
stands behind is serendipity. Two out of three is noise. Multiplying rather than adding is what stops
a junk row buying its way in on rarity alone — this is asserted directly by
`rarity_cannot_buy_its_way_past_the_quality_gate`.

## Rarity signals

| Signal | Weight | Why |
|---|---:|---|
| `lexical` | 40 | The strongest by a distance. An unusual noun in a title is what "you didn't know this existed" actually looks like in data. Far better than any category taxonomy, because feed categories are coarse and frequently wrong, while the words a merchant chose are specific by necessity. |
| `category` | 20 | A category with few products in it is a corner of the catalogue nobody browses. |
| `brand` | 15 | Measured honestly — share of the catalogue, not a curated list of "cool" brands. |
| `exclusivity` | 15 | Sold by one shop rather than all of them. |
| `novelty` | 10 | New to us. Weak, and it fades; it is what stops the surface showing the same twenty things forever. |

### Two things that are deliberately log-scaled

`lexicalRarity()` and `rarityOfShare()` both use `-log10(share) / 4`. Raw share is useless here: the
gap between a word appearing in 0.01% of titles and one appearing in 0.5% is enormous perceptually
and invisible linearly.

### The rarest word, not the average

"Draadloze bluetooth koptelefoon met ruisonderdrukking" is five common words and one specific one,
and the specific one is what tells you what the thing is. Averaging buries it.

### Unknown words score nothing

A word absent from the corpus is almost always a model number or a typo, not an exotic product.
Treating absence as maximal rarity would rank pure noise to the top, so unknown words are skipped
entirely rather than scored high.

## The quality gate

Hard zeroes — no image, no price, out of stock, or `giftable = false`. That last one matters more
than it looks: consumables and spare parts are extremely rare *and* extremely unwelcome, so the
giftability classifier's verdict does double duty here. Rarity is exactly the wrong measure for a
printer cartridge.

Softer multipliers — under €10 halves the score (the tail of cable ties and phone charms is rare for
the wrong reason), and a missing brand costs 20% (weak evidence of a white-label listing; plenty of
genuine finds are unbranded).

A title with fewer than two meaningful words is a hard zero. `ART.4471-B` is rare by construction and
worthless to show.

## Exclusivity is inverted against search on purpose

Search ranks *up* products carried by several shops — when you know what you want, more shops means a
better price. Serendipity ranks them *down*. Both are right, and the difference is what the visitor
is doing: comparing, or being shown something new.

## Where it runs

`ScoreSerendipity`, twice daily at 05:25 and 17:25 — after `ClassifyGiftability`, because the gate
reads its verdict. `CatalogueStats` is built **once per run** and reused for every row: serendipity is
a comparison against the rest of the catalogue, so per-row statistics would be both wrong and tens of
thousands of queries. Word frequencies are computed in Postgres (`unnest` + `regexp_split_to_array`)
rather than by pulling 70,000 titles across the wire.

## The `/surprise` surface

Rank first, randomise second. `ORDER BY random()` over the whole table would be slow *and* wrong — it
returns median products. Taking the top 200 by score and shuffling inside that slice means everything
shown genuinely earned its place, while a surface whose entire purpose is surprise does not show the
same six things to everyone forever.

The exclusion list travels in the URL, not in the session: it makes "show me more" idempotent and
back-button-safe. It is truncated to 60 ids server-side so a hand-edited URL cannot become an
unbounded `IN` clause.

### The card says what the thing is, not why we picked it

Changed 2026-08-10. Each card used to carry its loudest scoring signal — "almost no shop stocks it",
"a corner of the catalogue nobody browses", "a brand you probably have not heard of". The reasoning
was that a checkable claim beats an assertion, which is true and was the wrong thing to put there.

Read six at a time down a grid, every one of those lines is a sentence about *our ranking* and none
of them is about the object in the photograph. A visitor looking at an unfamiliar product does not
need to be told it is unfamiliar — that is the one thing they can already see. What they cannot see
is what it **is**, and the title alone rarely says ("Kärcher SC 3 Upright EasyFix").

So the line is now a description, taken from the merchant copy on the offers beneath the group, via
`App\Services\Catalogue\Excerpt`. The scoring signals still exist and still rank the page; they
simply no longer narrate it. `surprise.why.*` is gone from the language files.

Three things this had to get right, all of them because feed descriptions are the least disciplined
column in the catalogue:

- **Tags become a space before they are stripped.** `strip_tags()` alone turns
  `<li>Bluetooth</li><li>ANC</li>` into `BluetoothANC` — two real words welded into a nonsense one,
  which is worse than either the markup or nothing.
- **A scrap is not a description.** "Zwart", "One size" and the brand name alone all arrive in this
  field. Under a product title they read as a rendering bug, so anything under 30 characters is
  dropped and the card falls back to `surprise.by_brand`, or to nothing when there is no brand
  either.
- **Amazon is excluded at the query.** Invariant 6 — the offers are filtered to sources where
  `allowsCatalogueStorage()` is true. A convenience read of a description column is exactly the kind
  of thing that would quietly reproduce Amazon copy from our own store.

Fetched for all six groups in one query rather than through `bestOffer` per card, which is the N+1
this page would otherwise have shipped. The longest description wins: merchants selling the same
product supply wildly uneven copy, and on this field length is a crude but reliable proxy for
informativeness.

## Files

- `app/Services/Discovery/SerendipityEngine.php`
- `app/Services/Discovery/CatalogueStats.php`
- `app/Jobs/ScoreSerendipity.php`
- `app/Http/Controllers/SerendipityController.php`
- `app/Services/Catalogue/Excerpt.php` — merchant description → one printable line
- `resources/js/Pages/Surprise.tsx`
- `tests/Feature/SerendipityTest.php`
- `tests/Unit/ExcerptTest.php` — every shape a real feed row has arrived in

## Deferred

pgvector category centroids (Phase 8) would replace the lexical signal with a real semantic distance:
"how far is this from the middle of its category" is the question `lexicalRarity()` is approximating
with word frequencies. The approximation is good enough to ship and does not need an embedding
pipeline, a model, or a dimension to keep in sync.
