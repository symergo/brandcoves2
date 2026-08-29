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

`rarityOfShare()` uses `-log10(share) / 4`, and so does `lexicalRarity()` when it is scoring against
the whole market. Raw share is useless here: the gap between a word appearing in 0.01% of titles and
one appearing in 0.5% is enormous perceptually and invisible linearly.

### The rarest word, not the average

"Draadloze bluetooth koptelefoon met ruisonderdrukking" is five common words and one specific one,
and the specific one is what tells you what the thing is. Averaging buries it.

### A word is judged against its own category

Changed 2026-08-29. Measured across the whole market, this signal cannot tell an unusual product from
a category we have barely ingested — "almost nobody stocks this" and "we have thin coverage here"
produce the same number. With 200 board games and 40,000 phone accessories in the catalogue, every
board-game word looks exotic and the engine ranks an ingestion gap as a discovery.

Within its own category the question becomes the one worth asking: is this word unusual *for a
kitchen product*? "koptelefoon" among audio is furniture; "sous-vide" among kitchen is a find.

The scoped scale divides by the category's own dynamic range (`log10(1/share) / log10(total)`) rather
than a fixed four decades. A fixed window would hand bigger categories higher scores for free — in a
category of 200 the rarest possible word sits at 1/200, which four decades reads as 0.575, while a
category of 20,000 reaches 1.0 for the identical fact of "appears once". Mapping "appears once" to
1.0 everywhere is the only reading that compares across categories. Four decades is itself
`log10(10,000)`: the same formula against an assumed corpus size, which is why the market-wide
fallback keeps it.

Categories under **200 products** fall back to the market corpus. Below that the rarest a word could
possibly be is 1/200, and calling that maximal on the evidence of one listing is how noise reaches
the top.

### A part number is not a rare word

This is the bug that made the signal nearly useless, found 2026-08-29 while measuring the change
above.

The rule used to be "a word absent from the corpus is a model number or a typo, so skip it". **It
could never fire.** The corpus is built from these same titles, so every word in a product's title is
in it by construction — a part number included, with a count of exactly one, which the log scale
reads as maximally rare. `BEKO BM5DFT4941B` scored 1.000.

Measured on the real be-nl catalogue: **61.1% of products scored ≥0.99** on the signal carrying 40 of
the 100 rarity points. The strongest input to the ranking was very nearly a constant.

Two rules now say what that comment meant to say. A token containing a digit is a part number, a
capacity or a size (`bm5dft4941b`, `64gb`, `77mm`) — every title has one and none of them tells you
what the thing is. And a token used by fewer than **three** listings is not a word: a descriptive
noun recurs, while a token appearing once or twice in 136,000 is a typo, a transliteration or a model
name spelled without digits. Support is counted market-wide even when scoring within a category, so
"is this a word" stays a fact about the language and "how rare is it here" a fact about the category.

Saturation fell from **61.1% to 24.7%**, with the rest of the distribution actually populated. The
residual is category-unique words hitting the ceiling honestly, and is left alone — there is no
ground truth to tune against and a shape tuned by eye is what gets "cleaned up" later.

### The corpus is unaccented; the lookup was not

Fixed in the same pass. The corpus is built in SQL with `lower(unaccent(title))` and was read back in
PHP with `mb_strtolower` alone, so every accented word missed: the corpus held "cafe", the lookup
asked for "café", `isset` said no, and the word was skipped as unknown. The signal was quietly
dropping accented nouns in the two Dutch markets and the French one — where the distinctive words
live. Folded by table rather than `iconv('ASCII//TRANSLIT')`, whose output differs across glibc, musl
and Windows.

## The quality gate

Hard zeroes — no image, no price, out of stock, or `worth_showing = false`. That last one matters
more than it looks: consumables and fitment are extremely rare *and* extremely unwelcome, so the
giftability classifier's verdict does double duty here. Rarity is exactly the wrong measure for a
printer cartridge.

**`worth_showing`, not `giftable`** — changed 2026-08-29. This surface is not suggesting a present,
so the gift engine's "over €500 is a decision rather than a suggestion" rule has no business gating
it; an expensive unusual object is the best thing that can land on a Cove. That one flag was keeping
9,040 rows off the editorial surfaces. See [giftability.md](giftability.md).

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
