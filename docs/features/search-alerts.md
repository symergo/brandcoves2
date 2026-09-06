---
name: Watching a search
area: Search / Alerts
status: Active — new 2026-09-06; in-app notification only
date_added: 2026-09-06
---

# Watching a search

"Tell me when something new matches this, under this price." The price alert's machinery pointed
at a query rather than a product: the search page is where the intent is expressed, and until now
the only thing that could be watched was one product at a time.

## The shape

- **`search_alerts`** — one row per person per term per market, unique on the three. `term` is
  stored normalised (`SearchAlert::normalise()`: trimmed, lower-cased, one space between words) so
  "Lego" and "lego " are one watch. `max_price` is cents or null for any price.
- **`seen_group_ids`** is what makes "new" mean new. Seeded with what the search matched when the
  watch was set — that is what the person was looking at, so none of it is news — and extended
  with every id the search has matched since. Capped at 1,000; the oldest fall off, and a product
  that left the results for months and came back is, from the watcher's chair, new again.
- **`CheckSearchAlerts`** runs once a day at 06:30, after the overnight grouping. It runs each
  watch through `SearchService::matchingGroupIds()` — the stored query only, no live connectors
  (`liveTerm: ''`), no pagination, no facets, no logging — and writes one `search_match`
  notification naming how many new products matched, linking to the search with its ceiling.
  Unique (`ShouldBeUnique`), as the other scheduled jobs are.
- **The control** is `Components/WatchSearch.tsx`, under the heading on a search with a term:
  a button, a small panel with an optional ceiling, a sign-in link for a guest. The controller
  sends `watch` (`watching`, `id`, `maxPrice`, `requiresAccount`), null on the landing.

## Why the stored catalogue only

A scheduled job spending bol and Amazon requests on every watched term every morning is exactly
the cost the search throttle exists to bound, and a live result would be folded into the catalogue
anyway by the next person who searched. The overnight ingest and grouping are what change the
answer; the check runs after them.

## Not done

- **Email.** In-app only, like price alerts were until 2026-09-06. The same `AlertMail` shape
  would carry it; the count and the search link are already in the payload.
- **A page listing your watches.** Each is stopped from the search it watches; there is no
  overview. `/notifications` shows what they produced.
- **Announcing a price drop on an already-seen product.** A watch says "new", not "cheaper"; the
  price alert on the product page says "cheaper".
