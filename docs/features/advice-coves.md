---
name: Advice Coves
area: Content / Editorial
status: Active — 8 subjects × 4 markets seeded
date_added: 2026-08-30
---

# Advice Coves

**Eight articles about how to shop, in four markets, salvaged from three
WordPress sites and rewritten from nothing.**

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
  [shop-coves.md](shop-coves.md) follows and for the same reason.
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

**And it skips the `testing` environment.** This is the decision in the change
worth recording. `RefreshDatabase` migrates and *then* opens its transaction, so
seeding from a migration puts 32 published Coves into the baseline of every test
in the repository. Measured: it failed 32 of them — mostly fixture arithmetic,
`assertSame(1, DailyPickSet::count())` becoming 33.

The alternative was rewriting those 32 tests to tolerate the content, which
would bake this article set into the assumptions of every unrelated test
forever. The behaviour is covered instead by `AdviceCoveSeederTest` against the
same service the migration calls.

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

## Deliberately absent

- **`es`.** An unpublished market with no supply. An untranslated Cove is worse
  than an absent one, and the file simply has no `es` key; nothing needs
  changing but the content when that market opens.
- **A `focus_keyphrase`.** Nothing rebuilds an advice Cove, so the field stays
  null rather than carrying a guess.
- **Any product.** `CoveKind::Advice` is the one kind whose minimum is zero, and
  the prose is the substance. Several of these articles link to *other* Coves
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
