<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Enums\Market;
use App\Services\Charts\ChartDemand;
use Illuminate\Support\Facades\DB;

/**
 * How ordinary everything in one market's catalogue is.
 *
 * Serendipity is a comparison, not a property: a sous-vide circulator is only
 * surprising *relative to* a catalogue full of headphones and kettles. So the
 * scorer needs the whole distribution before it can judge one row, and loading
 * that per product would be tens of thousands of queries.
 *
 * Built once per scoring run, held in memory, thrown away. For 70,000 groups
 * that is a few megabytes and three queries.
 */
final class CatalogueStats
{
    /**
     * Below this a category cannot say anything about rarity.
     *
     * Within a category of n products the rarest a word can possibly be is
     * 1/n, so a category of 50 would call a word appearing in a single title
     * "maximally rare" on the evidence of one row. 200 buys at least 2.3
     * decades of range, which is enough for the ordering to mean something.
     * Smaller categories fall back to the market-wide corpus.
     */
    private const MIN_CATEGORY_SAMPLE = 200;

    /** How many listings must use a token before it counts as a word. See {@see isWord()}. */
    private const MIN_WORD_SUPPORT = 3;

    /**
     * @param  array<string, int>  $categoryCounts
     * @param  array<string, int>  $brandCounts
     * @param  array<string, int>  $tokenCounts  how many groups contain each title word
     * @param  array<string, array<string, int>>  $categoryTokenCounts  the same, per category
     * @param  array<int, float>  $demand  group id => bestseller-chart strength, 0-1
     */
    private function __construct(
        public readonly int $total,
        private readonly array $categoryCounts,
        private readonly array $brandCounts,
        private readonly array $tokenCounts,
        private readonly array $categoryTokenCounts = [],
        private readonly array $demand = [],
    ) {}

    public static function build(Market $market): self
    {
        $total = (int) DB::table('product_groups')
            ->where('market', $market->value)
            ->whereNotNull('min_price')
            ->count();

        /*
         * `min_price IS NOT NULL` on all three, matching `$total`.
         *
         * It was absent here, so `categoryShare()` divided a count over the
         * whole table by a total over the priced rows only — every share came
         * out slightly high and every category therefore slightly less rare
         * than it is. Small, and wrong in a way that got worse as the
         * proportion of unpriced rows grew.
         */
        $categories = DB::table('product_groups')
            ->where('market', $market->value)
            ->whereNotNull('category')
            ->whereNotNull('min_price')
            ->groupBy('category')
            ->select('category', DB::raw('count(*) as n'))
            ->pluck('n', 'category')
            ->map(fn ($n) => (int) $n)
            ->all();

        $brands = DB::table('product_groups')
            ->where('market', $market->value)
            ->whereNotNull('brand')
            ->whereNotNull('min_price')
            ->groupBy('brand')
            ->select('brand', DB::raw('count(*) as n'))
            ->pluck('n', 'brand')
            ->map(fn ($n) => (int) $n)
            ->all();

        /*
         * Word frequencies across every title in the market.
         *
         * This is the signal that actually finds "you didn't know this
         * existed" — an unusual noun in a title is a far better indicator than
         * any category taxonomy, because feed categories are coarse and often
         * wrong, while the words a merchant chose to describe a thing are
         * specific by necessity.
         *
         * Computed in Postgres rather than PHP: unnest+regexp_split over an
         * indexed column beats pulling 70,000 titles across the wire.
         */
        $tokens = DB::select(<<<'SQL'
            SELECT word, count(DISTINCT id)::int AS n
            FROM (
                SELECT id, unnest(regexp_split_to_array(lower(unaccent(title)), '[^a-z0-9]+')) AS word
                FROM product_groups
                WHERE market = ? AND min_price IS NOT NULL
            ) t
            WHERE length(word) > 3
            GROUP BY word
        SQL, [$market->value]);

        $tokenCounts = [];
        foreach ($tokens as $row) {
            $tokenCounts[$row->word] = (int) $row->n;
        }

        /*
         * The same frequencies again, but counted inside each category.
         *
         * This is the fix for the signal's original sin: measured across the
         * whole market, "rare" and "we have thin coverage of this category"
         * produce the same number. If the catalogue holds 200 board games and
         * 40,000 phone accessories then every board-game word looks exotic, and
         * the engine ranks an ingestion gap as a discovery.
         *
         * Within its own category the question becomes the one actually worth
         * asking: is this word unusual *for a kitchen product*? "koptelefoon"
         * among audio is furniture; "sous-vide" among kitchen is a find.
         *
         * Only categories at or above MIN_CATEGORY_SAMPLE are built, which also
         * bounds the memory: the long tail of small categories is the bulk of
         * the distinct (category, word) pairs and none of the signal.
         */
        $bigCategories = array_keys(array_filter(
            $categories,
            fn (int $n): bool => $n >= self::MIN_CATEGORY_SAMPLE,
        ));

        $categoryTokenCounts = [];

        if ($bigCategories !== []) {
            $placeholders = implode(',', array_fill(0, count($bigCategories), '?'));

            $categoryTokens = DB::select(<<<SQL
                SELECT category, word, count(DISTINCT id)::int AS n
                FROM (
                    SELECT id, category, unnest(regexp_split_to_array(lower(unaccent(title)), '[^a-z0-9]+')) AS word
                    FROM product_groups
                    WHERE market = ? AND min_price IS NOT NULL AND category IN ({$placeholders})
                ) t
                WHERE length(word) > 3
                GROUP BY category, word
            SQL, [$market->value, ...$bigCategories]);

            foreach ($categoryTokens as $row) {
                $categoryTokenCounts[$row->category][$row->word] = (int) $row->n;
            }
        }

        /*
         * Which of these products a retailer's bestseller chart knows about.
         *
         * Loaded here rather than queried by the scorer so that SerendipityEngine
         * keeps its contract: catalogue statistics in, a number out, no network
         * and no clock. Demand is a statistic about this market's catalogue like
         * the other three.
         */
        $demand = app(ChartDemand::class)->scores($market);

        return new self(max(1, $total), $categories, $brands, $tokenCounts, $categoryTokenCounts, $demand);
    }

    /**
     * How strongly a retailer's bestseller chart vouches for this product, 0-1.
     *
     * Zero means "no evidence", not "nobody buys it" — most of a catalogue has
     * never charted. Callers must treat it as an absence, never as a penalty.
     */
    public function demand(int $groupId): float
    {
        return $this->demand[$groupId] ?? 0.0;
    }

    /** Whether this market has any chart data at all to vouch with. */
    public function hasDemandData(): bool
    {
        return $this->demand !== [];
    }

    /** Share of the catalogue this category occupies, 0-1. */
    public function categoryShare(?string $category): float
    {
        if ($category === null) {
            return 0.0;
        }

        return ($this->categoryCounts[$category] ?? 0) / $this->total;
    }

    public function brandShare(?string $brand): float
    {
        if ($brand === null) {
            return 0.0;
        }

        return ($this->brandCounts[$brand] ?? 0) / $this->total;
    }

    /**
     * How rare the rarest meaningful word in this title is, 0-1.
     *
     * The *rarest* word, not the average: "draadloze bluetooth koptelefoon met
     * ruisonderdrukking" is five common words and one specific one, and it is
     * the specific one that tells you what the thing is. Averaging buries it.
     *
     * Scored against the product's own category where that category is big
     * enough to have an opinion, and against the whole market otherwise. See
     * the comment on the per-category corpus in {@see build()} for why: across
     * the whole market this signal cannot tell an unusual product from a
     * category we have barely ingested.
     */
    public function lexicalRarity(string $title, ?string $category = null): float
    {
        $scoped = $category !== null && isset($this->categoryTokenCounts[$category]);

        $counts = $scoped ? $this->categoryTokenCounts[$category] : $this->tokenCounts;
        $total = $scoped ? $this->categoryCounts[$category] : $this->total;

        $words = preg_split('/[^\p{L}\p{N}]+/u', $this->fold($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rarest = 1.0;
        $considered = 0;

        foreach ($words as $word) {
            if (mb_strlen($word) <= 3) {
                continue;
            }

            if (! isset($counts[$word]) || ! $this->isWord($word)) {
                continue;
            }

            $considered++;
            $rarest = min($rarest, $counts[$word] / $total);
        }

        if ($considered === 0) {
            return 0.0;
        }

        /*
         * Log scale. Raw share is useless here: the difference between a word
         * in 0.01% of titles and one in 0.5% is enormous perceptually and
         * invisible linearly.
         *
         * Scoped, the divisor is the category's own dynamic range rather than a
         * fixed number of decades. A fixed window would hand bigger categories
         * higher scores for free — in a category of 200 the rarest possible
         * word sits at 1/200, which four decades reads as 0.575, while a
         * category of 20,000 reaches 1.0 for the identical fact of "appears
         * once". Dividing by log10(total) maps "appears once" to 1.0 in every
         * category, which is the only reading that compares across them.
         *
         * Unscoped keeps the tuned four decades. The market corpus is always
         * ~10^5, so a fixed window is a fair one — and four decades is itself
         * log10(10,000), the same formula against an assumed corpus size.
         */
        $rarity = $scoped
            ? log10(1 / $rarest) / log10(max(10, $total))
            : -log10(max($rarest, 1e-4)) / 4;

        return max(0.0, min(1.0, $rarity));
    }

    /**
     * Is this token a word at all, or a part number wearing one's clothes?
     *
     * The engine used to answer this with "is it absent from the corpus", on
     * the reasoning that an unknown token is a model number or a typo. That
     * rule cannot fire: the corpus is built from these same titles, so every
     * word in a product's title is in it by construction — a model number
     * included, with a count of exactly one, which the log scale reads as
     * maximally rare. Measured before this was added, **61% of the be-nl
     * catalogue scored ≥0.99** on the signal carrying 40 of the 100 rarity
     * points. The strongest input to the ranking was very nearly a constant.
     *
     * Two rules, both restatements of what that comment meant to say:
     *
     * - **A token with a digit in it is a part number, a capacity or a size.**
     *   "bm5dft4941b", "kis86afe0", "64gb", "77mm". None of them tells you what
     *   the thing is, and every title has one.
     * - **A word almost nothing else uses is not a word.** A genuinely
     *   descriptive noun recurs; a token appearing in one or two listings out
     *   of 136,000 is a typo, a transliteration, or a model name spelled
     *   without digits. Three is the smallest count that can distinguish "rare"
     *   from "unique", and being wrong here is cheap — the title has other
     *   words, and the rarest surviving one still decides.
     *
     * Support is counted market-wide even when scoring within a category, so
     * the two questions stay separate: "is this a word" is a fact about the
     * language, "how rare is it here" is a fact about the category.
     */
    private function isWord(string $word): bool
    {
        if (preg_match('/\d/', $word) === 1) {
            return false;
        }

        return ($this->tokenCounts[$word] ?? 0) >= self::MIN_WORD_SUPPORT;
    }

    /**
     * Lowercase and strip accents, matching Postgres' `unaccent`.
     *
     * The corpus is built with `lower(unaccent(title))` in SQL and was looked
     * up here with `mb_strtolower` alone, so every accented word missed: the
     * corpus holds "cafe", the lookup asked for "café", and `isset` said no.
     * Unknown words are skipped, so the signal was quietly dropping accented
     * nouns — in the two Dutch markets and the French one, which is where the
     * distinctive words live.
     *
     * Folded by table rather than `iconv('ASCII//TRANSLIT')`, which produces
     * different output on glibc, musl and Windows.
     */
    private function fold(string $text): string
    {
        return strtr(mb_strtolower($text), [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', 'ø' => 'o', 'æ' => 'ae',
        ]);
    }
}
