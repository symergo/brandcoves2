<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Enums\Market;
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
     * @param  array<string, int>  $categoryCounts
     * @param  array<string, int>  $brandCounts
     * @param  array<string, int>  $tokenCounts  how many groups contain each title word
     */
    private function __construct(
        public readonly int $total,
        private readonly array $categoryCounts,
        private readonly array $brandCounts,
        private readonly array $tokenCounts,
    ) {}

    public static function build(Market $market): self
    {
        $total = (int) DB::table('product_groups')
            ->where('market', $market->value)
            ->whereNotNull('min_price')
            ->count();

        $categories = DB::table('product_groups')
            ->where('market', $market->value)
            ->whereNotNull('category')
            ->groupBy('category')
            ->select('category', DB::raw('count(*) as n'))
            ->pluck('n', 'category')
            ->map(fn ($n) => (int) $n)
            ->all();

        $brands = DB::table('product_groups')
            ->where('market', $market->value)
            ->whereNotNull('brand')
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

        return new self(max(1, $total), $categories, $brands, $tokenCounts);
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
     */
    public function lexicalRarity(string $title): float
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rarest = 1.0;
        $considered = 0;

        foreach ($words as $word) {
            if (mb_strlen($word) <= 3) {
                continue;
            }

            // A word absent from the corpus is usually a model number or a typo
            // rather than an exotic product. Treating it as maximally rare
            // would rank noise to the top, so unknown words are skipped.
            if (! isset($this->tokenCounts[$word])) {
                continue;
            }

            $considered++;
            $rarest = min($rarest, $this->tokenCounts[$word] / $this->total);
        }

        if ($considered === 0) {
            return 0.0;
        }

        /*
         * Log scale. Raw share is useless here: the difference between a word
         * in 0.01% of titles and one in 0.5% is enormous perceptually and
         * invisible linearly. log10 over four decades maps that to 0-1.
         */
        return max(0.0, min(1.0, -log10(max($rarest, 1e-4)) / 4));
    }
}
