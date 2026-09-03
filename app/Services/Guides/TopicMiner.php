<?php

declare(strict_types=1);

namespace App\Services\Guides;

use App\Enums\Market;
use App\Models\GuideTopic;
use App\Models\PopularRank;
use App\Models\ProductGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns 30 days of searches into a ranked queue of guide topics.
 *
 * The premise: write about what people demonstrably ask for *here*, not what a
 * keyword tool says the internet asks for. A site's own search log is the only
 * demand signal that is both real and unavailable to competitors.
 *
 * Nothing is published from this class. It produces *candidates*, ranked, for a
 * human or the builder to pick from — a topic queue you cannot inspect before it
 * generates is a content farm.
 */
class TopicMiner
{
    /** Rolling window. Long enough to smooth a quiet week, short enough to catch a season. */
    private const WINDOW_DAYS = 30;

    /** Below this, one person searching twice looks like a trend. */
    private const MIN_VOLUME = 5;

    /**
     * A guide needs products to be a guide.
     *
     * Five in stock. Below that it is a list with gaps, and a "best X" page with
     * three entries reads as thin to a reader and to a crawler.
     */
    private const MIN_PRODUCTS = 5;

    /**
     * The point past which more products stop improving a topic's score.
     *
     * Named because it is now load-bearing twice: `score()` saturates here, and
     * `availableProducts()` stops counting here. The second only stays correct
     * while the first does — raise this and the count gets more expensive again,
     * on the largest market first.
     */
    private const SUPPLY_SATURATION = 30;

    /**
     * Words that carry no topic.
     *
     * Clustering on the head noun means a stop list per language. Kept small
     * deliberately: an aggressive list merges genuinely different topics, and
     * "goedkope koptelefoon" and "koptelefoon" *should* merge while
     * "draadloze koptelefoon" arguably should not.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'de', 'het', 'een', 'van', 'voor', 'met', 'goedkope', 'goedkoop', 'beste',
        'the', 'a', 'for', 'with', 'best', 'cheap', 'top',
        'le', 'la', 'les', 'des', 'pour', 'avec', 'meilleur', 'meilleure', 'pas',
        'el', 'los', 'las', 'para', 'con', 'mejor', 'mejores', 'barato',
    ];

    /**
     * Mine, rank and store candidates for one market.
     *
     * @return int how many candidates were written
     */
    public function mine(Market $market): int
    {
        $queries = $this->recentQueries($market);
        $clusters = $this->cluster($queries);

        // Merged before scoring, not after, so a topic that both charts and gets
        // searched for is one candidate with both pieces of evidence rather than
        // two rows competing for the same slot.
        $clusters = $this->withChartTopics($market, $clusters);

        $written = 0;

        foreach ($clusters as $topic => $cluster) {
            /*
             * Cast, because PHP silently converts a numeric-string array key to
             * an int. A real search for "4090" or "2024" therefore arrives here
             * as an integer and blows up on the string type hint — which is what
             * happened on the first run against a live search log, and never
             * happened in tests because every fixture query is a word.
             */
            $topic = (string) $topic;

            $available = $this->availableProducts($market, $topic);
            $charting = $cluster['charting'] ?? 0;

            /*
             * A high-volume topic with no products is not a guide, it is a gap
             * — and a genuinely useful thing to know. It is stored with its
             * count rather than dropped, so admin can see "180 searches, 0
             * products" and go and find an advertiser who sells them.
             */
            $score = $this->score($cluster['volume'], $cluster['zero'], $available, $charting);

            GuideTopic::updateOrCreate(
                ['market' => $market->value, 'topic' => $topic],
                [
                    'member_queries' => array_slice($cluster['queries'], 0, 25),
                    'search_volume' => $cluster['volume'],
                    'chart_entries' => $charting,
                    'available_products' => $available,
                    'score' => $score,
                    // Only claimed when the chart is the *only* evidence. A
                    // topic people are searching for here is a search topic
                    // however many products chart, and mislabelling it would
                    // hide the fact that we have real first-party demand.
                    ...($cluster['volume'] > 0 ? [] : ['origin' => 'chart']),
                    // Never overwrite a decision a human already made.
                    ...(GuideTopic::query()
                        ->where('market', $market->value)
                        ->where('topic', $topic)
                        ->whereIn('status', ['queued', 'rejected', 'published'])
                        ->exists()
                        ? []
                        : ['status' => 'candidate']),
                ],
            );

            $written++;
        }

        return $written;
    }

    /**
     * The next topic worth building a guide for.
     *
     * Ripe means: enough demand, enough products, not already written, and not
     * rejected. Ordered by score so the best available topic goes first.
     *
     * **An in-season seasonal topic wins outright**, whatever its score. Not a
     * hedge — a timing argument. A Halloween Cove written on 20 October is nearly
     * worthless and the same Cove written on 1 August is an asset for a decade,
     * and no evergreen topic's score can outweigh a window that is about to shut.
     * See `SeasonalTopics` for why the log alone cannot see a season coming.
     */
    public function ripest(Market $market): ?GuideTopic
    {
        $seasonal = app(SeasonalTopics::class)->ripest($market);

        if ($seasonal !== null) {
            return $seasonal;
        }

        return GuideTopic::query()
            ->where('market', $market->value)
            ->whereIn('status', ['candidate', 'queued'])
            ->whereNull('guide_id')
            // Either kind of demand evidence qualifies. A market with no search
            // log yet still has a queue, which is the point of mining charts at
            // all — but a chart-only topic still ranks below a searched-for one,
            // because score() weights them that way.
            ->where(fn ($q) => $q
                ->where('search_volume', '>=', self::MIN_VOLUME)
                ->orWhere('chart_entries', '>=', self::MIN_PRODUCTS))
            ->where('available_products', '>=', self::MIN_PRODUCTS)
            // A topic the builder has just failed on would otherwise sit at the
            // head of the queue forever, and everything behind it is unreachable.
            ->notRecentlyAttempted()
            ->orderByDesc('score')
            ->first();
    }

    /**
     * @return list<array{query: string, volume: int, zero: int}>
     */
    private function recentQueries(Market $market): array
    {
        return DB::table('search_log')
            ->where('market', $market->value)
            ->where('hour_bucket', '>=', now()->subDays(self::WINDOW_DAYS))
            ->groupBy('query')
            ->havingRaw('sum(search_count) >= ?', [2])
            ->select(
                'query',
                DB::raw('sum(search_count)::int as volume'),
                DB::raw('sum(zero_result_count)::int as zero'),
            )
            ->orderByDesc('volume')
            // Bounded: the long tail of one-off queries is noise, and the head
            // is where the guides are.
            ->limit(500)
            ->get()
            ->map(fn ($r) => ['query' => (string) $r->query, 'volume' => (int) $r->volume, 'zero' => (int) $r->zero])
            ->all();
    }

    /**
     * Group queries by their head noun.
     *
     * Crude on purpose. "koptelefoon", "beste koptelefoon" and "koptelefoon
     * draadloos" are one topic; splitting them splits the volume that justifies
     * writing anything. Trigram clustering was the alternative and it merges
     * "koptelefoon" with "koptelefoonhouder", which is a different product.
     *
     * @param  list<array{query: string, volume: int, zero: int}>  $queries
     * @return array<string, array{queries: list<string>, volume: int, zero: int}>
     */
    private function cluster(array $queries): array
    {
        $clusters = [];

        foreach ($queries as $row) {
            $topic = $this->headNoun($row['query']);

            if ($topic === null) {
                continue;
            }

            $clusters[$topic] ??= ['queries' => [], 'volume' => 0, 'zero' => 0];
            $clusters[$topic]['queries'][] = $row['query'];
            $clusters[$topic]['volume'] += $row['volume'];
            $clusters[$topic]['zero'] += $row['zero'];
        }

        return array_filter($clusters, fn (array $c) => $c['volume'] >= self::MIN_VOLUME);
    }

    /**
     * Fold in topics a bestseller chart is shouting about.
     *
     * The miner's premise — write about what people demonstrably ask for *here* —
     * has one structural hole: a new market has no search log, so the queue is
     * empty exactly when guides would do the most good. A retailer's chart
     * categories are somebody else's demand measurement, available on day one.
     *
     * Merged into the existing clusters rather than written separately, so a
     * topic with both kinds of evidence stays one candidate. Nothing here
     * publishes: these land in the same reviewable queue as everything else,
     * which is the property that keeps this a topic queue rather than a content
     * farm.
     *
     * @param  array<string, array{queries: list<string>, volume: int, zero: int, charting?: int}>  $clusters
     * @return array<string, array{queries: list<string>, volume: int, zero: int, charting?: int}>
     */
    private function withChartTopics(Market $market, array $clusters): array
    {
        $latest = PopularRank::latestCapturedOn($market);

        if ($latest === null) {
            return $clusters;
        }

        $rows = DB::table('popular_ranks as r')
            ->join('chart_categories as c', function ($join) use ($market): void {
                $join->on('c.source', '=', 'r.source')
                    ->on('c.external_id', '=', 'r.category_external_id')
                    ->where('c.market', '=', $market->value);
            })
            ->where('r.market', $market->value)
            ->where('r.captured_on', $latest)
            // The market-wide chart is not a topic. "Everything bol sells" has
            // no head noun and would cluster onto whichever long word it
            // happened to be named after.
            ->where('r.category_external_id', '!=', PopularRank::OVERALL)
            ->groupBy('c.name')
            ->havingRaw('count(DISTINCT r.external_id) >= ?', [self::MIN_PRODUCTS])
            ->select('c.name', DB::raw('count(DISTINCT r.external_id)::int as charting'))
            ->orderByDesc(DB::raw('count(DISTINCT r.external_id)'))
            ->limit(200)
            ->get();

        foreach ($rows as $row) {
            // Through the same head-noun reduction as a search query, so
            // "Koptelefoons" and a search for "beste koptelefoon" land on one
            // topic instead of two near-identical guides.
            $topic = $this->headNoun((string) $row->name);

            if ($topic === null) {
                continue;
            }

            $clusters[$topic] ??= ['queries' => [], 'volume' => 0, 'zero' => 0];
            $clusters[$topic]['charting'] = max(
                $clusters[$topic]['charting'] ?? 0,
                (int) $row->charting,
            );
        }

        return $clusters;
    }

    /**
     * The longest non-stopword token.
     *
     * Longest rather than first, because in Dutch the compound *is* the noun:
     * in "draadloze koptelefoon" the topic is the second word, and in
     * "koptelefoon draadloos" it is the first. Length picks it out in both
     * without needing a parser.
     */
    private function headNoun(string $query): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $candidates = array_filter(
            $words,
            fn (string $w) => mb_strlen($w) > 3 && ! in_array($w, self::STOPWORDS, true),
        );

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $candidates[0];
    }

    /**
     * How many in-stock, presentable groups a guide could actually be built from,
     * counted only as far as anything downstream can tell the difference.
     *
     * The ceiling is the consumer's own. {@see self::score()} rejects anything
     * under `MIN_PRODUCTS` and then saturates — "30 products is not three times
     * better than 10, it is the same guide with a longer shortlist" — so every
     * number above `SUPPLY_SATURATION` was computed and immediately discarded.
     *
     * Counted exhaustively, it was why the Daily Cove had not built in two of
     * five markets since 18 August. This runs once per candidate topic, and each
     * run was a full `COUNT(*)` over the presentable catalogue with a correlated
     * full-text `EXISTS` — 117,129 rows on be-nl. Measured on production
     * 2026-09-02: 17 of 24 active queries were this one, the queue workers sat
     * at 0.1% CPU waiting on Postgres, and `BuildDailyEdition` hit its
     * 900-second timeout nightly. The cost scaled with catalogue size, which is
     * exactly why en (16k groups) almost always succeeded, nl-nl (60k) failed
     * intermittently, and be-nl (117k) and be-fr (103k) never finished at all.
     *
     * `fromSub` rather than `->limit(...)->count()`: Laravel discards the limit
     * when it wraps a query in an aggregate, so the ceiling has to sit inside
     * the subquery — which is also what lets Postgres stop scanning once it has
     * found enough rows, and is the entire saving.
     */
    private function availableProducts(Market $market, string $topic): int
    {
        $matching = ProductGroup::query()
            ->select('product_groups.id')
            ->forMarket($market)
            ->presentable()
            ->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('products')
                ->whereColumn('products.group_id', 'product_groups.id')
                ->where('products.status', 'active')
                ->whereRaw(
                    'products.search_vector @@ websearch_to_tsquery(bc_text_config(products.market), ?)',
                    [$topic]
                ))
            ->limit(self::SUPPLY_SATURATION);

        return DB::query()->fromSub($matching, 'capped')->count();
    }

    /**
     * Demand, weighted by whether we can actually answer it.
     *
     * Zero-result searches count *extra*, not less: someone searching for
     * something we do not stock is telling us about a gap, and a guide is one
     * of the few things that can fill it without an advertiser. But only when
     * products exist to write about — a topic with real demand and no products
     * scores near zero, correctly, and sits in admin as a to-do rather than a
     * to-write.
     */
    private function score(int $volume, int $zeroResults, int $available, int $charting = 0): float
    {
        if ($available < self::MIN_PRODUCTS) {
            return 0.0;
        }

        // log, so one runaway query cannot dominate the queue forever. Zero
        // searches scores zero rather than log10(2)·40 — a chart-only topic must
        // not collect twelve points of first-party demand it does not have.
        $demand = $volume > 0 ? log10($volume + 1) * 40 : 0.0;
        $gap = log10(max(1, $zeroResults) + 1) * 15;
        // Saturates: 30 products is not three times better than 10, it is the
        // same guide with a longer shortlist. `availableProducts()` stops
        // counting at this same number, so the two must move together.
        $supply = min(1.0, $available / self::SUPPLY_SATURATION) * 25;

        /*
         * Chart evidence, weighted below our own searches on purpose.
         *
         * A bestseller chart is one retailer's customers, in one country,
         * shopping in a shape that retailer's own merchandising decided. Our
         * search log is the people who actually came here. So this fills the
         * queue when the log cannot — on a new market, or for a category nobody
         * has thought to search for yet — without ever outranking a topic the
         * audience has asked for directly.
         *
         * Capped at 20 against demand's 40 for exactly that reason.
         */
        $external = min(1.0, $charting / 40) * 20;

        return round($demand + $gap + $supply + $external, 2);
    }

    public static function slugFor(string $topic, string $prefix): string
    {
        return Str::slug($prefix.'-'.$topic);
    }
}
