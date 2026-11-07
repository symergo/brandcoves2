---
name: Advice Coves
area: Content / Editorial
status: Active — 10 subjects; 8 in four markets, 2 in three
date_added: 2026-08-30
---

# Advice Coves

**Ten articles about how to shop. Eight salvaged from three WordPress sites
and rewritten from nothing, two written here because only a gift site would
write them.**

`bstore.be`, `bstore.nl` and `webprice.eu` are the v1 estate: roughly 280 pages
of Amazon and online-shopping writing accumulated since 2016, most of it last
edited between 2019 and 2021. This feature is what is left after reading it.

The prose lives in [`resources/content/advice-coves.php`](../../resources/content/advice-coves.php).
`App\Services\Content\AdviceCoveSeeder` puts it in the database as `advice`
Coves, read at `/{market}/guides/{slug}`.

## What was taken, and what was not

The inventory was pulled from each site's `wp-json` API — 131, 94 and 55 items.
What survived is the evergreen middle: how consumer rights work, how to read a
marketplace listing, how to judge a shop, a price, a review, a refurbished unit,
a customs charge, a phishing text.

What was dropped, and why the dropping is the interesting half:

- **News.** Dozens of 2020–21 Prime Video press releases, Covid trading notes,
  Brexit-week explainers. They were current once.
- **Anything whose substance was a number.** There were three "is Prime worth
  it" articles across the sites and every one of them was a price list, so every
  one of them was wrong within a year. This is now a standing rule for the file:
  no prices, fees, delivery times or subscription costs, the same rule
  [shop-coves.md](shop-coves.md) follows and for the same reason. *(Since 2026-11-07 the
  writer's rule reads "no product prices": an amount or a period set by a law, stated with
  the rule it comes from, is allowed. See "Consumer journalism" below.)*
- **Product round-ups.** "Top 20 garden products", "3 mini projectors". Those
  are `guide` Coves — they need a curated shortlist against a live catalogue,
  which is the planner's job and not a content file's.

## Nothing was ported, because the old text was wrong

Not one sentence carries over, and that was not fastidiousness.

The consumer-rights article was the most-linked page across all three sites. It
was last edited in 2019. It said refunds must arrive within 30 days (it is 14),
that failing to inform a consumer of their withdrawal right extends it by three
months (it is twelve) — and it said both of those on the same page as the
correct figures, having been half-edited at some point and never reconciled.

The one worth dwelling on is subtler. **The Belgian and Dutch sites served
byte-identical text**, including "you get two years' guarantee on new goods".
That is broadly right in Belgium and actively harmful in the Netherlands, where
there is *no fixed guarantee term at all*: Dutch law asks what you could
reasonably expect of that product at that price, which for an expensive
appliance is well beyond two years. The ACM runs a standing campaign about it
precisely because shops keep saying two. Telling a Dutch reader "two years"
talks them out of a claim they still have.

So these are keyed by **market, not by language**. `be-nl` and `nl-nl` read the
same Dutch under different consumer law, and being able to say that is what
`App\Enums\Market` is for. The two consumer-rights articles are genuinely
different pieces; most of the others differ only in wording, and can diverge
further without a schema change because each is already its own row.

### Every legal claim carries a commencement date

Because a rule with a date can be re-checked by whoever reads this next, and
"recently the EU decided" cannot. The four load-bearing ones, verified against
primary sources in August 2026:

| Rule | In force | Where it is used |
|---|---|---|
| Price reductions measured against the lowest price in the prior 30 days | 28 May 2022 | `crossed-out-prices` |
| Belgian presumption that a defect pre-existed, for the full 2 years | 1 June 2022 | `consumer-rights` (be-nl, be-fr) |
| GPSR: listings name the manufacturer and an EU responsible person | 13 Dec 2024 | `who-sells-this`, `buying-outside-the-eu` |
| €150 customs-duty exemption removed; flat interim duty per tariff line | 1 July 2026 | `buying-outside-the-eu`, `parcel-phishing` |

The last one is why this batch was worth writing rather than lightly editing: it
landed eight weeks before these articles were written, every source article
describes the world before it, and it gives the phishing piece a genuinely new
angle — small, *real* customs charges on cheap parcels began in exactly the
month that "pay €2.99 to release your parcel" stopped being implausible.

## The two that came from here

Added 2026-09-05, in `be-nl`, `nl-nl` and `en`. Neither has an ancestor in the
estate, because the estate was written by shops about shopping — and both of
these are about the gap between the person who paid and the person holding the
thing, which is what a gift site is *for*.

- **`gift-returns`** — the withdrawal right and the legal guarantee are
  contractual, and the contract is between the shop and whoever paid. The
  recipient is not a party to it. Everything a reader actually needs follows
  from that one fact: the giver has to make the request, the refund goes to the
  giver's card, and the only route that does *not* run through the giver is the
  one the law says nothing about — gift receipts, December windows, credit
  notes. Which is why half the article is about shop policy, and why it says so
  plainly rather than presenting goodwill as a right.
- **`parcel-never-arrived`** — risk passes to the consumer when they, or a third
  party *they* named, take physical possession. EU Consumer Rights Directive
  article 20, in force **13 June 2014**; art. VI.44 WER in Belgium, art. 7:11 BW
  in the Netherlands. So a parcel left with a neighbour the courier chose has
  not been delivered, and the counterparty is the shop rather than the carrier
  the reader has no contract with. The thirty-day rule (CRD art. 18) is the only
  other number in it.

`parcel-never-arrived` is deliberately distinct from `parcel-phishing`: that one
is about a message that is a lie, this one about a message that is true and
still does not mean what the shop says it means. Each links to the other.

**Three markets, not four.** `be-fr` carries the original eight and will carry
these when somebody writes them in French. A partial set is the intended state
of the file — it is keyed by market precisely so a market can differ — and the
seeder, the guides index and the hreflang pairing all already handle a subject
one market does not have.

## Every article names its drawing

`resources/content/advice-coves.php` gained a `scene` key per topic, and
`/guides` now puts that drawing on every card and at the top of every advice
article. See [cove-scenes.md](cove-scenes.md).

**It sits beside the market keys, not inside each of them**, because it belongs
to the *subject* and the subject is what the markets share.
`kopen-buiten-de-eu` and `buying-from-outside-the-eu` are one article about
customs duty wearing two languages; a scene per market would be the same
decision made four times and forgotten in one of them — a shelf with a hole in
it, in exactly one market, found by a reader.

So the seeder lifts `scene` out before it walks the markets (or it would be
reported as a market typo), and reports a value that is not an article scene
rather than falling back to the default drawing — because a silent fallback is
how a misspelling survives.

This is what made the shelf worth drawing at all: in all three of these markets
`/guides` is **entirely** Advice Coves, so it was eight identical rectangles of
text.

## Amazon links

The first substantive Amazon mention in each article carries an
`[[amazon:TERM]]` token; later mentions are plain text on purpose, because a
paid link on every occurrence of a word turns an article about not being misled
into something that is visibly trying to move you.

The token resolves through `AmazonSearchLink`, so **the Associates tag table is
the allowlist**: `nl-nl`, `be-nl` and `be-fr` have tags and link; `en` and `es`
have none and render the words unlinked. Issue a tag for a fourth market and
these articles start linking with no edit to the content. See
[amazon-compliance.md](amazon-compliance.md#a-third-path-amazon-named-inside-an-article)
for the `rel="sponsored"` requirement and why deep ASIN links are refused.

## Seeding: a command, and a migration that skips the tests

Both exist and they do the same thing.

`bc:seed-advice-coves` is the mechanism — idempotent, matched on
`(market, slug)`, and it **refreshes only rows whose `editorial_source` is still
`seed`**. Anything a person edited in the panel is reported as kept and left
alone; `--replace` overrides that and asks first. `published_at` is stamped once
and never moves, or every re-run would re-date the whole section to the top of
"newest first".

`2026_09_03_000100_the_advice_coves_move_in` calls the same service, so the
articles arrive on production during the next deploy's `migrate` step without
anybody logging in to run anything.

**A change to the file needs a new migration, not an edit to that one.**
`2026_09_05_000400_two_more_advice_coves_and_a_drawing_on_each` is the same call
again, and it is what carries the two new subjects and the ten scenes onto
production. Migrations are forward-only here: the first one has already run
everywhere, so editing it would change nothing on any deployed database and
would quietly diverge a fresh `migrate` from a live one.

Re-running is safe by construction, and that is exactly the property the seeder
was built around — the second migration rewrites the eight existing articles in
place to add their drawing, leaving prose, URLs and `published_at` untouched. A
drawing is not a republication. It also prints its `kept` list rather than only
counting it: those are the articles a person has edited, which the run
deliberately did *not* draw and which somebody now has to set by hand in the
planner. Silence there would read as success.

**And it skips the `testing` environment.** This is the decision in the change
worth recording. `RefreshDatabase` migrates and *then* opens its transaction, so
seeding from a migration puts 32 published Coves into the baseline of every test
in the repository. Measured: it failed 32 of them — mostly fixture arithmetic,
`assertSame(1, DailyPickSet::count())` becoming 33.

The alternative was rewriting those 32 tests to tolerate the content, which
would bake this article set into the assumptions of every unrelated test
forever. The behaviour is covered instead by `AdviceCoveSeederTest` against the
same service the migration calls.

**And both skip a fresh, empty database** (added 2026-09-26). The seeder reads
today's content file and writes today's row shape, but on a fresh `migrate` it
ran against the schema of its own date. The content names scenes that
`daily_pick_sets_scene_check` only accepts from `2026_09_05_000300` on, so a
fresh `migrate` died in the first of the two with a check violation. An empty
`daily_pick_sets` is what a fresh database looks like and no deployed one was
(Dailies existed long before), so the guard changes nothing where these have
run. **On a new environment, run `php artisan bc:seed-advice-coves` after
`migrate`.**

### The bug this flushed out

One of those 32 was not fixture arithmetic. `ContentPromotionTest` hit a unique
violation on `cove_plans_market_slug_idx`, and underneath it was a real defect
in `ContentEnvelope` that had nothing to do with this feature.

Both importers keyed a Cove on `kind === 'persona' ? slug : drop_date`. That was
a correct reading of the world when `daily` and `persona` were the only kinds,
and it went silently wrong at the guide fold, which added four more — **five of
the six kinds are dateless**. A guide, seasonal, advice or shop row therefore
took the date branch and was matched on `['market' => …, 'drop_date' => null]`,
which Laravel renders as `drop_date IS NULL`. That does not match nothing. It
matches *every dateless row in the market*.

So importing a guide plan would find some unrelated advice plan, fill it with
the guide's attributes, save it, and report an update: one plan silently
overwritten, one never created. It only surfaced as an error in the case where
the overwritten row's new slug was already taken by a third plan.

`ContentEnvelope::naturalKey()` now asks `CoveKind::isDated()` instead, and keys
dateless rows on `(market, slug)` — without the kind, because the slug namespace
is one per market *across* kinds. A row with nothing to match on returns null and
is always created. Two regression tests in `ContentPromotionTest` cover it, and
both were confirmed to fail against the old key.

## Every seeded Cove gets a plan

`CovePlan::recordFor()` mints one, `used` and never `approved` — a record of
what was published, not an instruction the next build obeys. Without it these
would be the one kind of page an editor could not open and re-curate. See
[cove-planner.md](cove-planner.md).

## Consumer journalism: the writer's brief since 2026-11-07

The owner found the "Slim kopen" articles not informative. On production they averaged about
2,700 characters (some 400 words): a few paragraphs of principle, no rule, period or body named.
The owner's brief replaced `Defaults::ADVICE_SYSTEM`, nearly whole: an experienced consumer
journalist and SEO editor, writing so the reader understands the subject, knows the facts and
rules, and knows what to do.

**The structure, mapped onto the page.**

| The brief | Where it goes |
|---|---|
| SEO title (~60 characters) with the main keyword | `title`, the page's only heading. There is no subtitle slot. |
| Core answer, 2-3 sentences, under the title | `intro` (the blurb), up to 500 characters |
| Meta description, 155 characters | `meta_description`, the writer's own now. The plan's still wins. |
| 4-7 sections under questions people search | `## ` headings in the body, which the page draws since this change |
| Checklist or steps, 5-7 | a list in the body (`- ` or `1. ` lines) |
| FAQ, 3-5, not already answered | `faq` |
| 900-1300 words | body up to 12,000 characters, 6,000 output tokens |
| 2-4 internal links, sources where needed | the link tokens, and `[[source:URL\|label]]` to the domains in `giftcoves.editorial_sources` |

**The inputs.** Main keyword: the plan's `focus_keyphrase`. Search questions: the plan's new
`search_questions` (admin field and API `searchQuestions`), not `queries`, which are product
search terms. Readers: the market's consumers, its own country's rules first. Today's date, so
"be current" has a date to be current to. On a rebuild, the published article (title, intro,
body, FAQ), with the instruction to keep what is strong and rewrite the rest.

**What changed in the rules.** "No prices" became "no product prices": the brief asks for
amounts and periods, and a legal limit or a statutory period is a fact about a rule, stated with
the rule it comes from (the commencement-date rule above still applies). A shop's own policy is
still never stated as fact. Never a specific product, never an invented test, no absolute legal,
medical or financial advice.

**Sources.** `[[source:...]]` links out only to the listed domains (governments, regulators,
ombudsmen, European consumer centres, consumer organisations), https only, in a new tab. A model
can still invent a path on an allowed domain, so the prompt asks for the home page unless it is
sure of the page, and an authored article's sources are checked by whoever writes it.

Only the `/guides` articles are told about headings, lists and sources (`promptContract(article:
true)`); a shop or brand page, and every column, prints paragraphs and is not. Elsewhere a heading
prints as its words and a list as its items. Tests: `CoveArticleBuildTest::an_advice_article_is_
briefed_with_its_keyword_questions_and_the_article_it_rewrites`, `CoveMarkupTest`,
`ProseCardsTest::headings_and_lists_are_blocks_of_their_own`,
`CoveDraftApiTest::an_article_takes_search_questions_and_a_column_refuses_them`.

## Deliberately absent

- **`es`.** An unpublished market with no supply. An untranslated Cove is worse
  than an absent one, and the file simply has no `es` key; nothing needs
  changing but the content when that market opens.
- **A `focus_keyphrase` on the shipped set.** The seeder writes none, so the field stays null
  rather than carrying a guess. An advice Cove written over the API may send one.
- **Any product.** `CoveKind::Advice` has a minimum of zero, like the two entity kinds, and the
  prose is the substance. Several of these articles link to *other* Coves
  with `[[guide:slug]]`, which is checked at seed time to be a slug that exists
  in the same market.

## Files

- `resources/content/advice-coves.php` — the prose, and the rules it is held to
- `app/Services/Content/AdviceCoveSeeder.php` — the write, and what it refuses to overwrite
- `app/Console/Commands/SeedAdviceCovesCommand.php` — `bc:seed-advice-coves`
- `database/migrations/2026_09_03_000100_the_advice_coves_move_in.php`
- `app/Services/Guides/CoveMarkup.php` — the `[[amazon:]]` token
- `tests/Feature/AdviceCoveSeederTest.php`, `tests/Unit/CoveMarkupTest.php`

## See also

- [shop-coves.md](shop-coves.md) — the sibling, and where the content rules come from
- [cove-planner.md](cove-planner.md) — how a Cove of any kind is decided
- [amazon-compliance.md](amazon-compliance.md) — what an Amazon link may be

## Pictures and shop links in an authored article (owner, 2026-11-07)

For the gift-card guides ("include pictures of the gift cards, and links to where to buy them";
"don't limit to products in database, research broadly online"): two tokens, authored articles
only, so nothing in the builder's prompt mentions them.

- `[[image:URL|what it shows]]` alone on a paragraph is a photograph (`CoveMarkup::image()`,
  `ProseCards`, `ArticleImage` on the guide page): through the image proxy when it serves that
  host, otherwise the address as given; contained on white, at most 320px tall, left out if it
  will not load. The description is the alt text and is required. Inside a sentence the token is
  removed, as a figure's is. Plain surfaces (email, meta) skip it.
- `[[shop:URL|label]]` links to a shop (`CoveMarkup::shop()`), `nofollow noopener`, new tab. Any
  https shop, Amazon included at the owner's word; an Amazon address on the market's own
  storefront gets the Associates tag if it has none, and `sponsored`, since an untagged Amazon
  link earns nothing and looks exactly like one that does.

Both refuse anything but https, credentials and ports (`CoveMarkup::external()`), and a refused
one is reported like any rejected token. The API's body limit went from 20,000 to 60,000
characters ("don't limit yourself to the default limits if more info is available").