---
name: Gift landing pages
area: Gifting / SEO
status: Active — recorded nightly (morning catalogue run) per published market; recipient × interest only
date_added: 2026-09-26
---

# Gift landing pages: "gift ideas for dad who loves cooking"

Roadmap step 4, parts 1 and 2 ([../strategy.md](../strategy.md), engine E and "Naming and SEO").
A page for each pair of *who it is for* and *what they love* that the catalogue can actually fill:

> `/be-nl/gift-ideas/for/papa/koken`: **Cadeau-ideeën voor papa die van koken houdt**
> `/en/gift-ideas/for/dad/cooking`: **Gift ideas for dad who loves cooking**
> `/be-fr/gift-ideas/for/papa/cuisine`: **Idées cadeaux pour papa qui aime la cuisine**

and one per person, gathering their best pairs: `/en/gift-ideas/for/dad`.

## The brief is the page

A gift brief (`TasteBrief`: who, interests, budget, occasion, age, taste) is the object the
suggestion engine answers. Two things make it something a page can be built from:

- **It can be stored.** `TasteBrief::toArray()` / `fromArray()`. Only what somebody decided is
  kept: no market (whatever holds the brief already has one, and two copies can disagree), no
  limit or exclusions (they belong to one run of the engine). `fromArray()` drops any value outside
  the gift vocabulary rather than keeping a word the engine would quietly match nothing with;
  `problems()` names them, for the editorial API, which refuses instead.
- **It has an address.** `App\Services\Gift\BriefUrl`. The path carries the two things a page is
  *about*, recipient and interest, in the market's own words (`papa`, `koken`); the budget is
  `?budget=50-100` in euros. Everything else (age, style, things to avoid) is Find a gift's
  job, not an address.

## Why these URLs

- **`/gift-ideas/for/` in every market.** The owner had not ruled on the words; this was chosen.
  The section word stays `gift-ideas` everywhere, like the persona shelf it sits under, because the
  strategy keeps URLs as they are and a localised section word means a second route and a redirect
  table for one word. What the searcher types, "papa", "koken", is in the path, and that is the
  part that reads.
- **The `for` segment keeps them clear of the personas.** A persona is `/gift-ideas/{slug}`, one
  segment; a landing page is two or three. No persona can be shadowed, and
  `GiftLandingTest::persona_addresses_are_untouched` pins it.
- **The words live in `lang/*/site.php`** (`gift_landing.recipients.*.slug`,
  `gift_landing.interests.*.slug`), so `LocalisationTest` checks all four languages have them.
  Treat them as permanent: changing one moves the page, and the planner drops the row at the old
  address the next night.
- **Another language's words redirect.** `/be-nl/gift-ideas/for/dad/cooking` is a 301 to
  `/be-nl/gift-ideas/for/papa/koken`: a hand-built link lands, and a search engine is never shown
  the same page under two names.

## Only pages worth having exist

`PlanGiftLandingPages` (nightly, a step of the morning catalogue run after the market's grouping
and brand statistics since 2026-09-28, 05:40 before; after the list signals; by hand:
`php artisan bc:plan-gift-landings --market=be-nl`) walks every recipient × interest, asks the
suggestion engine for 24 products and counts the ones that **fit the interest** by the engine's own
verdict (`Suggestion::matchedInterests`). "The engine returned something" is not enough: with
nothing matching it falls back to a budget browse, so Find a gift never comes back empty, and
a page of that would be "for dad who loves fishing" showing a candle.

- **Eight or more** (`giftcoves.gift_landings.min_products`, the owner's minimum for a Cove) and the
  pair is recorded in `gift_landings`. Anything else answers 404, which keeps hundreds of thin
  combinations out of the index. A pair that drops under eight loses its row the next night.
- **A recipient's own page** exists when at least one of their pairs does, and links on to every
  pair. Its brief is three interests chosen from `hub_interests_by_recipient`: an ordered list per
  recipient (dad: DIY, drinks, gadgets; grandparent: gardening, reading, board games), taking the
  first ones that have a page. Only when too few do, the rest are filled in by product count.
  Why a list (2026-09-27): it used to be "most products first", and the count is the same for
  every recipient (the engine retrieves by interest) and stops at the page size, 24. Every interest
  tied, the tie fell to the enum order, and all ten recipient pages were cooking, coffee and
  photography: the same 24 products for mum, a colleague and a child. The list is an editorial
  guess, to be replaced by the data once recipient tags are common (see below).
- **`kids` is not walked** (`excluded_interests`): it says who a present is for, not something dad
  loves.
- **Some pairs never get a page** (`excluded_pairs`): a child × drinks (alcohol), coffee or hunting.
  Until 2026-09-27 every recipient had all of them.
- **Recipient × occasion** is not walked yet (the owner's order: recipient × interest first).

### The known weakness, and the switch for it

Retrieval is by interest; the recipient only reorders (products tagged for that recipient, by an
editor or by people's lists, rank first). So "dad who loves cooking" and "mum who loves cooking"
share most of their products today. Recipient tags are still rare, which is why the rule does not
demand them. `gift_landings.min_recipient_matches` (0 now) makes a page require that many products
carrying the recipient's tag; raise it once tags are common and the pages will differ more. The
owner has not ruled on this; it is the first thing to look at if search engines treat the pages as
near-duplicates.

## What the page says

All from templates (`GiftLandingCopy`), never from a model: the page is a web request
(invariant 1) and "gift ideas for dad who loves cooking" is a sentence a template gets right.

- **The heading leads with the searched phrase** ([page-titles.md](page-titles.md)). Each
  recipient carries the words between it and the interest (`who`), which is how Dutch gets
  *die/dat* right ("je kind **dat** van gamen houdt") and Spanish its *amante de* with the article
  folded in ("amante **del** café").
- **The listing title** is the heading when it fits the 48 characters a result shows, then a
  shorter lead ("Gifts for…", "Cadeau voor…"), then the bare "Dad who loves cooking". Measured
  after the words are filled in; `BriefUrlTest` renders every pair in every language and fails
  above 48. Five long pairs forced shorter words ("le nautisme", "del agua", "de la nieve").
- **One template intro**, no counts in it. The meta description carries the count.
- **Budget chips** (under 25, 25-50, 50-100, over 100) narrow the page with `?budget=`; the
  canonical stays the bare address, the rule filtered search follows ([search-urls.md](search-urls.md)).
- **"Search everything tagged for them"** under the products opens the search with the matching
  filters (`/be-nl/search?for=father&interest=cooking`, [search.md](search.md)): the page is the
  engine's best 24, the search is every tagged product with sorting and the other filters.
- **Sideways links only to recorded pages**: the same person's other interests, the same interest
  for other people, and the person's own page. Discover ("Of per persoon") links the person pages,
  and (until 2026-11-07) the sitemap listed them all. The gift ideas shelf (`/gift-ideas`) linked every person's page
  too, until the owner took that row off on 2026-09-28: the shelf is for personas. It is back since
  2026-11-24 (owner: "Put the row back"), closest people first, with the line to /gift-ideas/for under it.

Products are cached a day per page and budget, keyed on the night's `checked_at`, so the engine
runs once per page per day, not once per visitor.

## Search engines

- **Sitemap**: not listed since 2026-11-07 (the owner's four-kinds rule, [seo.md](seo.md#sitemaps)).
  Until then, exactly the recorded pages; the planner still clears that file's cache when it runs.
- **hreflang only between markets that both have the pair**, paired on (recipient, interest)
  rather than the path, since each market words the path its own way
  (`GiftLandingLinks::alternates()`; `Alternates::persona()` knows the `for` segment too). An
  alternate to a missing page makes a search engine discard the whole cluster.
- **Breadcrumbs** in JSON-LD: GiftCoves › Gift ideas › (the person's page) › this page.

## Drawn as the Find-a-gift results (2026-09-26)

A landing page draws its products with the shared results component
([find-a-gift.md](find-a-gift.md)): the gift cards with what each fits and "chosen by others", then
the ideas without a shop, Coves others made and *Ask other people*. The prop is `picks` (was
`products`). The cache holds the engine's verdict per product (`bc:gift-landing:v2:*`: id, fits,
chosen by others) rather than ids alone, so the page still runs the engine once a day per budget;
the products themselves are loaded fresh, and one gone since drops out. The ideas and the Coves are
read per request: both are one small query, and the Coves leave out the viewer's own.

## Find a gift's "Open as a page"

The Finder's results are a POST: nobody can bookmark, share or find them again. Under the results
a link opens the landing page nearest the brief (`GiftLandingLinks::pageFor()`): the first of its
interests with a page for that person, else the person's own page, with the budget carried over.
A saved person's relationship is free text ("mama"), so it is read with the search box's word
lists. No page, no button: a link to a page answering a different question would be worse than
none. The plan's noindex `/gift/for?…` page for briefs too specific for a landing was not built.

## Not yet

- An authored intro per page over the editorial API (same rule as Coves: written in the session,
  sent authored).
- Recipient × occasion pages ("for mum, Mother's Day").
- The noindex result page for briefs no landing covers.

## Files

- `app/Services/Gift/{TasteBrief,BriefUrl,GiftLandingCopy,GiftLandingLinks,GiftLandingPlanner}.php`
- `app/Models/GiftLanding.php`, `database/migrations/2026_09_26_000300_gift_landing_pages.php`
- `app/Jobs/PlanGiftLandingPages.php`, `app/Console/Commands/PlanGiftLandingsCommand.php`,
  `routes/console.php`
- `app/Http/Controllers/GiftLandingController.php`, `resources/js/Pages/GiftIdeas/Landing.tsx`,
  `routes/web.php` (`gift-ideas.landing`)
- `SitemapController`, `Alternates`, `GiftIdeasController` (the shelf), `GiftController` +
  `Pages/Gift/Wizard.tsx` (Open as a page)
- `config/giftcoves.php` (`gift_landings`), `lang/*/site.php` (`gift_landing`)
- Tests: `tests/Unit/TasteBriefTest.php`, `BriefUrlTest`, `GiftLandingTest`

## The nightly run was running out of time (2026-11-15)

`PlanGiftLandingPages` failed for three of the four markets on 2026-11-15, cut off at its half hour.
It makes about 400 engine runs per market, and the engine's candidate query then took four to five
seconds each (270 logged runs in one window, 1,240 seconds).

Measured on production (nl-nl, "cooking"): Postgres expected 104,000 candidate ids where there were
16,000, hash-joined them against every giftable group in the price band and read all 788,000 of
those. 9.7 seconds warm, 13.4 cold. `SuggestionEngine::pool()` now passes the ids as
`= ANY(ARRAY(...))`, so the groups are fetched by primary key: 2.3 seconds, the remainder being the
text search on `products`. The same rows come back. Find a gift uses the same query and gains the same.

The job's limit is an hour now, the most the `batch` queue allows. Not yet seen: a full night's run
with the new query.

## The first visit of the day was slow (2026-11-15)

Measured on production after a deploy: 5 to 15 seconds for a page nobody had opened that day, 0.15
seconds once stored; one answered 500 after 83 seconds while the server was busy. That same day the
search pages started linking these pages (`:gift_links`), so a crawler would have met them cold.
Two causes, two changes:

- **The page ran the engine again.** The stored answer is keyed on `checked_at`, which the nightly
  plan renews, so every night retired every page's answer, and the plan threw away the one it had
  just worked out. Now `GiftLandingPlanner::record()` stores it under the page's key
  (`GiftLanding::verdictsKey()`), six hours longer than the page's own day so the gap between two
  nights' runs is never a cold page. Only a `?budget=` page still runs the engine on a visit.
- **The engine spent 1.5 to 2 seconds keeping its picks varied.** `SuggestionEngine::diversify()`
  compared every candidate with every pick so far on each round and cut both titles into words each
  time: 83,000 comparisons for 300 candidates and 24 picks. It now keeps each candidate's penalty and
  raises it by the newest pick alone, with each title cut once: 7,200 comparisons, about 85 ms. The
  picks are the same, in the same order: 27 briefs over three markets were compared id by id and
  score by score before and after. Find a gift and the nightly plan (about 400 engine runs per
  market) gain the same.

What is left of a cold engine run is the text search on `products` (the candidate query above).

## Written copy for the strongest pages (2026-11-24)

Owner, on advice: the ~1,700 pages such as `/be-nl/gift-ideas/for/partner/mode` match "cadeau voor ..."
searches exactly, but every one carried the same templated sentence and they had been out of the
sitemap since 2026-11-07. `gift_landings.intro` and `meta_description` (migration `2026_11_24_000200`)
hold words written for one page; the page shows them instead of the template (paragraphs split on blank
lines), and a page with written copy is listed in the market's sitemap (`SitemapController`). The rest
stay indexable and linked, not submitted. The nightly planner upserts its own columns only, so it never
overwrites them; a page that falls under the product minimum is still removed, copy and all.

Set over the editorial API, as a publish: `GET /gift-landings?market=` lists every page with its copy,
`POST /gift-landings/copy` `{market, pages: [{path, intro, metaDescription}]}` writes up to 100, all or
nothing (an unknown path refuses the batch), `intro: null` returns a page to the template. Test:
`GiftLandingTest::written_copy_replaces_the_template_survives_the_planner_and_enters_the_sitemap`.

## A way in (2026-11-24)

Owner: "The landing pages have no real entry point. I mean, no link on site." Measured that day: no
link to a landing page on the home page, Gift Ideas, the Coves overview, Find a gift, a Daily, a
guide, a product or a brand page; one on a search page. The "for whom" row lived on /discover-cove
only, and the owner had taken it off the Gift Ideas shelf on 2026-09-28 (the shelf is for personas).

- `/{market}/gift-ideas/for` (`GiftIdeasController::forWhom()`, `GiftIdeas/ForWhom.tsx`): every
  landing page per person, the closest people first, each person's own page as the heading and the
  interest pages under it, the ones with written copy first. In the sitemap and the page cache.
- Linked from one line under the Gift Ideas heading and from the footer's Explore row on every page
  (where the market has gift pages), so it is a click from anywhere and crawled from everywhere.
- Product pages: "Ook een goed cadeau voor: papa die graag kookt, ..." under the interest tags,
  from an editor's tags only (`GiftLandingLinks::forProduct()`): the product's own person and interest
  first, then the same interest's pages with written copy. The interest chips keep opening Find a gift
  (owner, 2026-09-30); this line is the page links.
- A sentence in the Find a gift entry on /help, four languages.

Test: `GiftLandingTest::the_pages_have_a_way_in_a_directory_the_sitemap_and_their_products`.

## Adult products and word matches kept off (2026-11-24)

The tagging review of that day (owner: "a tv is not for a child that likes technical stuff", then "Do 1
to 3") found, live: a masturbator and a strap-on on `kind/ontspannen` (judged `wellness`, never
`adult`), an erotic gift set on `kind/snoep-en-chocolade` and erotic lingerie on nl-nl `kind/vissen`
(untagged, found by the word search; "visnet" is fishnet), the same products on the teacher's,
grandparent's and colleague's pages, and 15 erotica pages for a teacher, a grandparent, a colleague, a
host and a mum.

- `SuggestionEngine::pool()` keeps a product out of every brief that does not ask for `adult` when any
  of its tags (judged, inferred or the crowd's) is `interest:adult`, or its category or title is erotic
  (`ADULT_CATEGORY`, `ADULT_TITLE`: titles too, because one lingerie set is filed under "Drysuit").
- `gift_landings.excluded_pairs` blocks `adult` for every recipient but a partner.
- Landing pages take tagged products only (`TasteBrief::$taggedOnly`, set by `GiftLanding::toBrief()` and
  `BriefUrl::toBrief()`): a stored, indexable page takes no word-search guesses. Find a gift keeps them.

Test: `GiftLandingTest::adult_products_and_word_matches_stay_off_the_pages_and_erotica_is_for_a_partner_only`.

The data half (2026-11-25): the 178 judged products whose category or title is erotic but whose tags
lacked `interest:adult` got it over the editorial API (176 be-nl, 2 nl-nl), with their `vibe:` tags
dropped since that value is gone. The category filter also catches "seks…" and penis pumps.

## A budget per person, and a child's page only from children's things (2026-11-25)

Same review: televisions and network switches on the page for a child who likes technology. Two causes.
Retrieval is by interest, and the recipient is only five points of the score, so `tech` brought every
television. And a page without a budget had the €500 giftability ceiling, with the scoring's sweet spot
near it, so the dearest products led.

- `gift_landings.default_budget`: the page without `?budget=` has a ceiling per recipient, in euros. Child
  75, teacher 30, colleague and host 40, grandparent 100, everyone else 150. Set by `BriefUrl::toBrief()`
  and the hub brief, so it is stored in `gift_landings.brief` and the planner counts within it. A chosen
  `?budget=` band replaces it; the "any budget" link is the page itself, so it means "the usual budget for
  this person".
- A child's landing page (pair and hub) takes only products judged for a child: `interest:kids` or an age
  band up to 12 (`GiftTags::YOUNG_CHILD`), by an editor or the crowd, or `recipient:child` with no age of
  13 or older. That last condition because `recipient:child` means "their child, who may be forty"
  (gift-tags.md), and production had a €508 VR headset and a men's tracksuit tagged so. On a landing page,
  "kind" means up to 12; a teenager is another page (not built yet). Find a gift is untouched: its giver
  reads the results and refines, a stored page cannot.

Production on the day: 22,789 products with a young-child tag (21,061 be-nl, 925 be-fr, 516 nl-nl, 287
en). The review estimated that about 82 of the 157 child pages keep their eight products.

Test: `GiftLandingTest::a_childs_page_takes_child_products_within_the_childs_budget`.

## Adults only, for anybody under 18 (2026-11-25)

The child filter above trusts the tags, and a tag can be wrong: a knife set tagged `age:10-12`. So a
brief for somebody under 18 (a child's landing page, or Find a gift with an age band up to 13-17) also
leaves out:

- products tagged `age:18+`, by an editor or the crowd. A new vocabulary value, a verdict rather than a
  band: the wizard's age question reads `GiftTags::AGE_BANDS`, which does not carry it. The tagging brief
  (`.claude/skills/giftcoves-tag-products/reference/brief.md`) says when to use it;
- products filed under `SuggestionEngine::ADULTS_ONLY_CATEGORY`: televisions, projectors and home cinema,
  knives, saws and drills, motorcycle gear, wine, beer, whisky and cocktail gear, network and security
  equipment, large appliances, lingerie, tobacco. Written from production's own category names and
  checked against them: 34,519 giftable products in 207 categories on the day. Toy and baby versions are
  spared (`ADULTS_ONLY_EXCEPT`: "Speelgoedzaag", "Projecteur pour bébé"). Words that caught the wrong
  thing on that check, and how they were narrowed: "bier" caught "Jambiere" (now `^bier`, `bierglas`),
  "vape" caught "vapeur" (now a whole word), "couteau" caught "Plié au couteau" (now at the start), "home
  cinéma" caught DVDs.

A category rule, like the adult filter, so it holds for products nobody has judged, and it needs no
re-tagging run. Find a gift with only "child" and no age is untouched: that child may be grown up.

The auto-tagger (`auto_tags.skip_categories`) no longer infers interests for Vibrator, Dildo, the erotic
sets, Lingerie, Zakmes, Messenset and Motorhelm (review P9: Vibrator alone gave 1,602 products
"wellness").

Not done, on purpose:

- **A teenager page.** `child` is a `RecipientType`, and so would a teenager be: that enum is the
  recipient of every list, the wizard's first question and a CHECK constraint, so a new value is a
  migration and wording in four languages across the site, not a landing-page setting. Left for the
  owner to decide.
- **Raising `min_recipient_matches`** to tell the teacher, colleague and grandparent pages apart (review
  P10). Recipient tags are on 1.5% of be-nl products and under 0.3% elsewhere, so it would remove most of
  those pages. The budgets per person already separate them (teacher €30, colleague €40, grandparent
  €100); measure the overlap after the next planner run first.
- **A review queue** (P7). The filters above remove what it would list; reconsider if the next review
  still finds wrong products.

Test: `GiftLandingTest::a_childs_page_takes_child_products_within_the_childs_budget`.

## Names that have to read in a sentence (owner, 2026-11-26)

An interest's `name` in `lang/*/site.php` (`gift_landing.interests`) is dropped into "Cadeaus voor
mama die van … houdt", so it has to read there, not only as a menu label. "Die van wonen houdt" did
not: the Dutch name is "interieur" now. The `slug` stays `wonen`, because the pages are indexed under
it; a slug change needs a 301 from the old address.
