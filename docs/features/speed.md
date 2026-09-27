---
name: Speed
area: Core / Operations
status: Active
date_added: 2026-09-27
---

# Speed

What was done to make the site answer faster, and why each piece is shaped the way it is. It
follows the speed audit of 2026-09-27 (live page timings, the production database, the server, the
background work and the code of every page). The audit's finding: the site was slow for avoidable
reasons, not for lack of hardware. The server was 85% idle, and `product_groups` had been read end
to end 221,810 times.

Each helper working on the audit's follow-up adds its own section here.

## Indexes and slow queries

Every number below was measured on production with `EXPLAIN ANALYZE` on 2026-09-27, before the
change. The indexes are in `database/migrations/2026_09_28_001200_indexes_for_the_slow_queries.php`.

### The new indexes

| Index | Serves | Before |
|---|---|---|
| `product_groups (market, brand)` | the brand filter (brand pages, search's brand facet) | 255 ms, a read of the whole table |
| `product_groups (market, lower(brand))` | `NextSteps::sameBrand()`, `SearchLanding::soldHere()` | 173 ms |
| `product_groups (market, category, merchant_count DESC, first_seen_at DESC) WHERE worth_showing AND in_stock` | `CoveRail`'s "more from these categories" | a sort of every match |
| `products (merchant_id, market, status)` | a shop's offers in one market | the `merchant_id` index plus a filter |
| `products (merchant_deep_link text_pattern_ops)`, partial | `LinkRouter::feedMerchant()`, a pasted shop link | a filter over the shop's offers |
| `wishlist_collaborators (user_id)` | lists a person helps with | the unique index leads on `wishlist_id` |
| `wishlists (recipient_id)`, partial | the lists about a saved person | none |
| `restock_alerts (user_id)`, partial | a person's own alerts | the unique index leads on `group_id` |
| `cove_plans (edition_id)`, partial | from a published Cove back to its plan | none |
| `popular_ranks (market, captured_on)` | the latest charts of a market | the chart index leads on `source` |

Things worth knowing about them:

- **Built `CONCURRENTLY`**, so nothing is locked while they build; that cannot happen inside a
  transaction, hence `$withinTransaction = false`. A concurrent build that fails leaves an
  *invalid* index under its name, which `IF NOT EXISTS` would skip forever, so the migration drops
  such a leftover first. A re-run is always safe.
- **The rail index matches the query exactly**: the same two equality columns, then the same two
  sort columns in the same direction, and a condition (`worth_showing = true AND in_stock = true`)
  that the query repeats. Postgres then reads the first six entries and stops. Change the sort in
  `CoveRail` and the index stops helping.
- **`lower(brand)` must be spelled exactly that way** in a query for the expression index to apply.
  Both callers do (`whereIn(DB::raw('lower(brand)'), ...)`).
- **The deep-link index uses `text_pattern_ops`**, because a plain btree cannot answer `LIKE
  'https://shop/path%'` outside the C collation. It only helps because every pattern in
  `feedMerchant()` is anchored at the start; a leading `%` would make it useless.
- **The deep-link index skips links of 2000 bytes or more**, and `feedMerchant()` repeats that
  condition so the planner may use it. A btree entry has a hard ceiling of about 2.7 kB: without the
  limit one freak URL in a feed would fail the index build (and so the deploy), and afterwards fail
  every ingest that tried to store it. The longest deep link locally was 464 bytes; nobody pastes a
  2 kB product URL.
- **Expand only.** `products_merchant_id_index` is now covered by the new three-column index and can
  be dropped later, together with the never-used indexes the audit found.

### Queries rewritten

**Alternates across markets (804 ms).** `Alternates::forProducts()` and the per-product lookup
asked `WHERE identity_key IN (...)` with no condition on `market`. The only index on `identity_key`
is the unique `(market, identity_key)`, and a btree cannot be entered on its second column alone, so
Postgres read the whole table. Both now add `WHERE market IN (<every market>)`, which filters
nothing out and lets the index answer each pair. Locally 31 ms became 1.7 ms. Covered by
`ProductAlternatesTest`.

**The gift engine's pool (267-504 ms).** `SuggestionEngine::pool()` retrieved candidates with
`EXISTS (an offer matches the text) OR jsonb_exists_any(gift_tags, ...) OR
jsonb_exists_any(crowd_tags, ...)`. Two things kept it off every index:

1. `jsonb_exists_any()` is the function behind the `?|` operator, and a GIN index serves operators,
   not functions. The two tag indexes had been scanned zero times on production, ever.
2. An OR is only indexable when every branch is, and a correlated EXISTS never is, so the whole
   predicate read every giftable group and probed its offers.

It now collects candidate ids first, as a UNION of three independent SELECTs (offers by full text,
groups by editor tags, groups by crowd tags), each on its own index, and filters and sorts that set
with the same conditions as before. It is the same rewrite `SearchService::applyTextMatch()` went
through in August. The operator is written `??|` in PHP because a bare `?` is a PDO placeholder;
PDO hands Postgres a single `?`. Locally the pool went from 223 ms to 56 ms and returned the same
653 groups.

**Search's tag filters.** `SearchService::storedQuery()` used `jsonb_exists_any()` for the "who,
what they love, what for" filters too. It now uses `??|`, so both branches of its OR (editor tags,
crowd tags) can use their index and be combined in a bitmap.

### Before deploying

- The migration builds ten indexes on production tables, `products` being the largest. Concurrent
  builds are slower than plain ones but lock nothing. Run it outside the night job windows.
- Worth one read-only query on production first:
  `SELECT count(*) FROM products WHERE octet_length(merchant_deep_link) >= 2000` says how many
  offers the pasted-link lookup no longer matches (expected: none).
