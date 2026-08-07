---
name: Search & offer comparison
area: Search
status: Partial — schema, functions and indexes built and verified; the service and UI are Phase 2
date_added: 2026-08-07
---

# Search & offer comparison

Postgres does the searching. No Elasticsearch, no Meilisearch — one fewer service to run, back up
and keep in sync, and the catalogue is well within what Postgres handles comfortably.

## Two mechanisms, because they fail differently

### 1. Full-text — ranked term matching

`products.search_vector` is a **stored generated column**, so the vector is computed once at write
time rather than on every search.

```
setweight(title,    'A')   -- a term in the title outranks
setweight(brand,    'B')   -- the same term in the brand, which outranks
setweight(category, 'C')   -- the same term buried in a merchant's category string
```

Two things this had to get right:

- **Stemming is language-specific.** A Dutch stemmer will not reduce `chaussures` to `chaussure`.
  `bc_text_config(market)` maps the market to `dutch` / `french` / `spanish` / `english`.
- **`unaccent()` is declared STABLE, not IMMUTABLE**, so Postgres refuses it in a generated column or
  an index. Pinning the dictionary explicitly (`bc_unaccent()`) makes the call genuinely immutable.
  Without this, `creme` cannot match `crème` — not optional in a catalogue spanning three languages.

Offers are indexed rather than groups: offers carry per-merchant titles and categories, so indexing
them gives better recall than the group's single denormalised title.

> **Careful:** `bc_search_vector()` is baked into a stored generated column. Changing that function
> does **not** rewrite existing rows. That needs an explicit backfill migration.

### 2. Trigram — typo tolerance

**Query with the `<%` (word_similarity) operator, not `%`.**

`%` uses `similarity()`, which compares **whole strings**. Measured against a real product title:

| | score |
|---|---|
| `similarity('blutooth koptelefon', 'Draadloze Bluetooth Koptelefoon met ruisonderdrukking')` | **0.298** |
| `word_similarity(...)` same pair | **0.696** |

Postgres' default `similarity_threshold` is 0.3, so the whole-string comparison lands *just* under it
and a perfectly reasonable typo returns nothing. `word_similarity()` asks the question actually meant
— does this query match some run of words *inside* the title — and the same `gin_trgm_ops` index
serves both operators, so this is a query-side fix, not an indexing one.

`config('brandcoves.search.trigram_threshold')` is **0.45**: below Postgres' 0.6 word-similarity
default so single misspelled words still match, but not so low that unrelated products leak in.
Starting point only — it must be re-tuned against a real catalogue in Phase 2, because with two rows
in the table false-positive testing is meaningless.

## Offer comparison (Phase 2)

The plan, not yet built:

- Merge the stored index (Awin, ingested hourly) with a live bol query, Redis-cached 15 min.
- Live results are grouped into the stored graph **on the fly** — an incoming bol offer with a
  matching EAN joins an existing group and immediately becomes comparable.
- Results render as **group cards**: *"from €X · 3 offers across 2 stores"*.
- `store_lane_cap` = 8 per merchant in the "by store" view, so one recently-ingested merchant with a
  huge feed cannot monopolise every slot.
- Every query is logged to `search_log` — that table is the input to [buying-guides.md](buying-guides.md).

## Files

- `database/migrations/2026_08_07_000700_add_search_indexes.php`
- `config/brandcoves.php` (`search.*`)

## Verification

```sql
-- language-aware stemming
SELECT title FROM products
WHERE search_vector @@ to_tsquery(bc_text_config(market), 'koptelefoon');

-- accent folding: finds "réduction"
SELECT title FROM products
WHERE search_vector @@ to_tsquery(bc_text_config(market), 'reduction');

-- typo tolerance: use <%, not %
SELECT title, word_similarity('koptelefon', title) FROM products
WHERE 'koptelefon' <% title;
```

All three verified passing 2026-08-07 against a two-row fixture.
