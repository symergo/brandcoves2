<?php

declare(strict_types=1);

namespace App\Services\Guides;

use App\Enums\Market;
use App\Models\GuideTopic;
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

            /*
             * A high-volume topic with no products is not a guide, it is a gap
             * — and a genuinely useful thing to know. It is stored with its
             * count rather than dropped, so admin can see "180 searches, 0
             * products" and go and find an advertiser who sells them.
             */
            $score = $this->score($cluster['volume'], $cluster['zero'], $available);

            GuideTopic::updateOrCreate(
                ['market' => $market->value, 'topic' => $topic],
                [
                    'member_queries' => array_slice($cluster['queries'], 0, 25),
                    'search_volume' => $cluster['volume'],
                    'available_products' => $available,
                    'score' => $score,
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
            ->where('search_volume', '>=', self::MIN_VOLUME)
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

    /** How many in-stock, presentable groups a guide could actually be built from. */
    private function availableProducts(Market $market, string $topic): int
    {
        return ProductGroup::query()
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
            ->count();
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
    private function score(int $volume, int $zeroResults, int $available): float
    {
        if ($available < self::MIN_PRODUCTS) {
            return 0.0;
        }

        // log, so one runaway query cannot dominate the queue forever.
        $demand = log10(max(1, $volume) + 1) * 40;
        $gap = log10(max(1, $zeroResults) + 1) * 15;
        // Saturates: 30 products is not three times better than 10, it is the
        // same guide with a longer shortlist.
        $supply = min(1.0, $available / 30) * 25;

        return round($demand + $gap + $supply, 2);
    }

    public static function slugFor(string $topic, string $prefix): string
    {
        return Str::slug($prefix.'-'.$topic);
    }
}
