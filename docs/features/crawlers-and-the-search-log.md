---
name: Crawlers and the search log
area: Search / SEO
status: Active
date_added: 2026-09-08
---

# Crawlers and the search log

**How a crawler is kept from adding a pill.** Four layers, each catching what the one before
lets through, all landed between 2026-09-05 and 2026-09-08.

## The problem

`search_log` is the site's demand signal. It decides which buying guides get written
(`TopicMiner`) and what the popular-searches page prints (`SearchTermStats`). Until 2026-09-05 it
also drew the related-search chips. Every row in it is supposed to be a person wanting something.

Until 2026-09-05 the term chips above a result set were anchors that *narrowed* the query by
adding a word: `watch`, then `watch Smartwatch`, then `watch Smartwatch 44mm`. A crawler followed
every one, each landing logged a brand-new term, and the related-search chips drawn from the log
then linked to those terms, so the crawler had more to follow. On 2026-09-08 the production table
held 1.14 million rows and 963 thousand of them were strings such as "koptelefoon sound draadloze
uur hoofdtelefoon hoofdtelefoons earpads hoge dichtheid drivers bluetooth blue true core code
speelduur". Ten thousand a day were still arriving three days after the chips went, from crawlers
revisiting URLs they already knew.

## Layer 1: the chips are buttons

[Search.tsx](../../resources/js/Pages/Search.tsx) renders each term chip as a `<button>` that
calls `router.get()` with the server's own narrowing URL. A button navigates for a visitor and does
not exist for a crawler: there is no `href` to follow and nothing in the sitemap. The narrowing rule
itself stays on the server (`SearchController::terms()`, and `BrandController::terms()` on a brand
page), so the client never rebuilds a URL. This is what stopped the *supply* of new combinations. It
could not stop the ones already indexed.

## Layer 2: a named crawler is not logged

[`App\Support\Crawlers::looksLikeOne()`](../../app/Support/Crawlers.php) is a name match on the
user agent: the generic words (`bot`, `crawl`, `spider`, `fetch`, `preview`, `headless`) plus the
crawlers seen in this site's logs, and an empty user agent, which is a script rather than a
browser. `SearchQuery::fromRequest()` sets `logged` to false when it matches, and
`SearchService::search()` then skips `SearchLog::record()`. A name match, not a DNS
verification: nobody spoofs Googlebot to keep a search out of a statistics table.

## Layer 3: no cookie, no log

A crawler that does not announce itself still keeps no cookies. The same `fromAPerson()` check
requires the request to carry this site's session cookie (`config('session.cookie')`), which a
browser has from its second request onward. The price is a person's very first search after
landing from elsewhere, which is not a pattern yet; every search they make after it counts. This is
the layer that makes the guarantee unconditional: a crawler can identify itself however it likes
and still cannot write a row.

`SearchLogTest` holds the rules. `a_crawler_search_is_not_logged` sends no session cookie, so it
passes on this layer alone; the user-agent match in layer 2 has no test of its own until that test
sends the cookie.

## Layer 4: the log refuses what nobody types

`SearchLog::record()` drops a query over sixty characters or more than six words
(`SearchLog::MAX_LENGTH`, `MAX_WORDS`). Sixty because a pasted URL or a run of title fragments is
longer and a real question is not; six because "cadeau voor mijn moeder van 70" is six and the
minted strings start at seven. The migration
`2026_09_08_000100_the_search_log_forgets_the_long_terms` went further on what was already there
and deleted every row of more than one word: the two- to six-word steps of the crawler's walk look
exactly like queries, so the owner chose to keep the single words, the one shape no crawler minted,
and let the log fill again with what people type under the rules above.

This layer is a floor under the other three: it holds even for a request that somehow passes them,
and it is what cleaned up the past.

## What is left at read time

`SearchTermStats::publishable()` still applies the stop list, the spec pattern, the minimum
length, the volume floor and the zero-result exclusion before anything is printed; see
[popular-searches.md](popular-searches.md). Those rules are about what is *publishable*, which is a
different question from what is *demand*, and they stay because a real person types "pro" too.

## What to watch

- `select count(*) from search_log where length(query) > 60` on production should stay at zero
  after the deploy. If it climbs, something is calling `SearchLog::record()` without going through
  `SearchQuery::fromRequest()`.
- The daily row count should fall to the order of a few hundred. Before the fix it was tens of
  thousands, almost all of them crawler steps.
- A new crawler that keeps cookies would be the first thing to defeat layer 3. None seen so far.

## Files

- `resources/js/Pages/Search.tsx` — the chips as buttons
- `app/Support/Crawlers.php` — the user-agent match
- `app/Services/Search/SearchQuery.php` — `fromAPerson()`, the two request tests
- `app/Models/SearchLog.php` — `MAX_LENGTH`, `MAX_WORDS`, `worthLogging()`
- `database/migrations/2026_09_08_000100_the_search_log_forgets_the_long_terms.php`
- `tests/Feature/SearchLogTest.php`

## See also

- [popular-searches.md](popular-searches.md) — the read-time rules and the page they protect
- [search.md](search.md) — the vocabulary row the chips come from
- [seo.md](seo.md) — which search URLs are indexable at all

## Four crawlers are limited by name (2026-11-13, Amzn-SearchBot added 2026-11-14)

Amazonbot, SemrushBot, ExaSearchBot and Amzn-SearchBot made most of the search requests while
gift-idea pages for people timed out, and none of them is a search engine people find the site
through. `App\Http\Middleware\LimitHeavyCrawlers` refuses them search pages (403) and gives each
ten other pages a minute, counted per crawler name because they come from many addresses (429 with
`Retry-After` above that). `robots.txt` carries the same rules in a group of their own and is always
served. To add one: a line in `LimitHeavyCrawlers::CRAWLERS`; robots.txt follows from it.

## AI crawlers kept off search pages (owner, 2026-11-22)

In an hour sampled on 2026-11-22, 94% of the 9,242 requests were crawlers: GPTBot 2,431, ClaudeBot
1,943, meta-externalagent 1,407, Reflectionbot 1,041, Bing 482, KakaoBot 447. Most of it was search
pages for long terms pasted together from product titles, which are never cached and cost seconds of
database time each: 71 searches took over 5 s, two timed out at 30 s, and the server sat at a load of
4.5 to 5 on 6 cores.

GPTBot, ClaudeBot, meta-externalagent, Reflectionbot and KakaoBot are now refused search pages (403)
and have a robots.txt group saying so, but unlike the four above they are **not** slowed down
anywhere else and get no Crawl-delay: product, brand, guide and gift pages are what an AI assistant
can cite, and a search page is only a list of those. Google and Bing are on neither list. To add one:
a line in `LimitHeavyCrawlers::SEARCH_ONLY`.

`meta-webindexer` joined them on 2026-11-23 (owner): Meta's index for Meta AI search made 573 search
requests in 20 minutes, the slowest at 17 s, once meta-externalagent was refused. Facebook and
WhatsApp link previews come from `facebookexternalhit`, which is on neither list.

`Claude-SearchBot`, Anthropic's crawler for Claude's web search, joined them the same evening
(owner): 348 of the 609 search requests in 20 minutes, 1.5 s each at the median, with long terms
pasted together from product titles. The `claudebot` entry does not match its name. Kept off search
pages only, so its answers can still cite the product, brand and guide pages.

## Search and shop clicks from outside Europe are challenged (owner, 2026-11-23)

From 06:00 UTC on 2026-11-23 a scraper posing as Windows Chrome followed the site's own links:
search, product page, `/go`. 3,427 requests in 40 minutes from 3,084 IPs (residential proxies:
Bangladesh, the US, Singapore, Pakistan...), about 27 times the usual rate. Its short, unfamiliar
terms each started a live search (3 to 24 s a page, 453 over 3 s), and its `/go` clicks reach the
affiliate networks as clicks nobody made. A user-agent rule cannot see it and a per-IP rate limit
cannot either, since most IPs send one request.

The tell that looked best, a Referer from this site with no cookie, is useless here: an anonymous
visitor gets no cookie at all on purpose (CacheAnonymousPage), so that rule would refuse every real
first visit. Location is what is left: 86% of its search and `/go` requests came from outside Europe.

So a Cloudflare custom rule on the giftcoves.com zone (the fourth, after the data-centre challenge)
gives a **Managed Challenge** to requests outside Europe (`ip.src.continent ne "EU"`) whose path
contains `/go/`, `/zoek`, `/recherche`, `/search` or `/buscar`, except verified bots
(`cf.client.bot`, so Google and Bing pass). Product, guide and gift pages stay open everywhere. A
person outside Europe usually passes it without seeing anything, at worst after a second or two.
It lives in Cloudflare only, not in this repo: Security > WAF > Custom rules.
