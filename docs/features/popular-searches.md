# Popular searches

A public page, one per market, showing what this market searches for: **a ranked
column per period**, **rising fastest**, and **searched recently**. Linked from
the footer of every page.

`/{market}/popular-searches` → `PopularSearchesController` →
`App\Services\Search\SearchTermStats` → `resources/js/Pages/PopularSearches.tsx`.

## Why it exists

It is the replacement for the related-search chips, which sat under every result
set and were removed on 2026-09-05 — see [seo.md](seo.md) for the measurements.
What went with them mattered: they were the only links on a results page that
pointed anywhere other than back into itself, and a page with no outbound links
is a leaf a crawler stops at.

The shape is the point. One hub and a handful of grouped aggregates over one
cached pass, instead of a trigram scan under every result set whose cost grew
with the log it was scanning. It also answers the question the chips were
approximating badly, since trigram similarity finds queries that *look* alike
rather than queries that are related: "koptelefoon" pulled "koptelefoonhouder"
and never "oordopjes".

## A column per period

`columns` (3) periods side by side, newest on the left, each ranked on its own
and capped at `limit` (20). A single ranking over three months answers "what is
popular here" and nothing else; three periods side by side answer "what is
popular *now*, and what was popular before it" — the same rows, arranged so the
movement between them is visible rather than asserted.

The section carries no explanatory sentence and neither does the page: the
column headings are dates and the arrows have tooltips, so a paragraph saying
what a ranked list is was words for their own sake. Removed 2026-09-05, keys and
all.

**Weeks, not months.** `period` switches it in one word and months were the first
shape, but weeks won on the data: `search_log` held 26 days when this shipped,
which fills three weekly columns and leaves the third monthly one empty. An empty
column teaches a reader that the page is broken. Switch to `month` once the log
reaches back four of them — the oldest column needs a fourth period behind it for
its arrows.

Column headings are formatted server-side in the market's own language
("31 aug. – 6 sep.", "September 2026"): month and weekday names are the one piece
of copy the language files do not carry, and browser `Intl` support is not
something to rely on for a heading.

## The movement arrows

Each row carries its direction against **the column before it**, as an icon:
green triangle up (`sage`), terracotta triangle down (`accent`), an amber
four-point star for new, a dash for unchanged. The star is deliberately not a
triangle, so "new" is never read as a direction at a glance.

**No counts anywhere.** They are not rendered and they are not in the payload
either — shipping exact search volumes in the page source while choosing not to
print them would publish the same numbers to anyone who looked. Volume and the
trending lift decide the ordering inside the service and are stripped before the
props leave it. That also makes the arrow the only signal a row carries, which is
why its accessibility is not optional.

A row's position in its column *is* its rank for that period — the list is
ordered by volume and cut from the top — which is what makes comparing it against
the previous period meaningful. That baseline ranking is **uncapped**: a term now
at 20 may have been 200th, and cutting the baseline at the same `limit` would
report every such term as new.

**The baseline has no privacy floor**, deliberately. `min_volume` guards what
gets *published*; those ranks are never rendered, only the direction derived from
them, so applying the floor there would buy no privacy and would manufacture
false "New" badges — a term searched three times last week and forty this week
would read as new rather than as risen.

**No baseline means no arrows in that column.** The oldest column has a period
before it that the log may not reach, and marking all twenty of its rows "New"
would be a column of badges saying nothing — the absence of a baseline is not
evidence of novelty. They appear on their own once there is history behind it.

Colour never carries the meaning alone: the triangles point, new has a shape of
its own, and every state has a screen-reader label and a tooltip. A red and a
green triangle are the same triangle to a red-green colour-blind reader.

> This compared the whole 90-day ranking against the previous 90 days for about
> an hour on 2026-09-05. It was correct and completely invisible: that needs 180
> days of log before one arrow can appear, and there were 26. A correct
> comparison nobody will see for six months is not a comparison.

## The other two lists

**Rising fastest** — a *rate*, never a count. A term's searches per day over the
last `trending_days` divided by its rate across the rest of `window_days`, so a
term that has always sold steadily does not appear and one that has just started
moving does. Ranked by count it would return the same rows as the columns and the
section would be decoration.

The prior rate is smoothed by one search. Without it a term with no history
divides by zero and pins the top of the list — arithmetically silly, and the
noisiest possible answer, since a term with no history is exactly the one we know
least about. A term with nothing in the recent window is excluded outright: it is
not a rise however popular it once was.

**Searched recently** — established terms whose most recent search is newest.
Deliberately *not* "recently typed": see the privacy floor, which is the whole
reason this list can be published at all.

## Three rules, and why each is not a preference

**A term must have been searched `min_volume` times to appear in any published
list.** This is a privacy floor, not a tuning knob. `search_log` carries no
identity, which is what makes it non-personal — but a single unusual query
published on a public page can still be about one identifiable person, and
somebody who typed a name into a gift site did not publish it. The threshold is
what makes a listed term a *pattern* rather than an event.

It matters most for **searched recently**, which without it is a live feed of
what individuals are typing right now. With the floor it reads "established *and*
searched again lately", which is a different and publishable thing. Raise the
floor freely; never lower it to make a list longer. A short list is the correct
output of a quiet market.

**Searches that found nothing are excluded.** They are the most valuable rows in
the table — they are content gaps, which is why `SearchLog::record()` logs after
the count is known — but they belong to `TopicMiner`, not to a reader. A link to
"nothing matched" is a bad link on the one page whose whole job is linking
outward, and a public list of what this site cannot answer is an odd thing to
publish.

**Queries longer than 60 characters are dropped.** A pasted URL the Amazon parser
did not claim is logged as typed. It is neither a search anyone would click nor
something to print, and length is the right kind of blunt filter for it.

## Indexable, and its links are followed

Deliberately unlike the chips it replaces, which were `nofollow`ed and then
deleted. The difference is what the links point at. A chip row on a search page
offered terms *derived from that page*, so crawling one minted a query string
nobody had ever typed, which was logged and became another chip — an unbounded
supply of URLs feeding the table that generated them. Every term here has already
been searched by real people, so following one mints nothing new and the set is
bounded by the log rather than by the crawler's appetite.

A page with **every column and both lists empty** is `noindex, follow`. A market
that opened yesterday has no history, and a thin page spends crawl budget
belonging to products and guides — the same rule the filtered search variants
follow. It stays a real page for a visitor either way, with a sentence saying so
and a link to the search box, because the footer points here from every page in
every market.

## Cost

Grouped aggregates over `search_log` filtered by `(market, hour_bucket)`, which
the existing index covers: two per column — the published top, and the previous
period's ranking for the arrows — plus two for trending and one for latest. All
cached together under one key. No similarity operator anywhere; that was the
entire problem with what this replaces.

`cache_ttl` is **a day**. The columns are whole periods and the arrows compare
one against another, so nothing here changes meaningfully between one hour and
the next — refreshing more often only means more visitors paying for the rebuild.
A day also gives the arrows a stable meaning: the page a reader sees in the
morning says the same thing that night.

## Knobs

`config/giftcoves.php` → `search.popular`: `columns` (3), `period` (`week`),
`limit` (20 per column), `min_volume` (5), `window_days` (90, for the two lists),
`short_list` (20), `trending_days` (7), `cache_ttl` (86400 — a day).

## Files

- `app/Services/Search/SearchTermStats.php`
- `app/Http/Controllers/PopularSearchesController.php`
- `resources/js/Pages/PopularSearches.tsx`
- `resources/js/Layouts/SiteLayout.tsx` (the footer link — its only inbound link)
- `lang/{en,nl,fr,es}/site.php` (`popular_searches.*`)
- `tests/Feature/PopularSearchesTest.php`
