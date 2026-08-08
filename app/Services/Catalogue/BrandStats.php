<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\Market;
use App\Models\BrandStat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recompute `brand_stats` for a market.
 *
 * Every number a brand page asserts is computed here, once a night, so the page
 * itself is one indexed read. The alternative — aggregating over
 * `product_groups` per request — puts a GROUP BY on the critical path of a page
 * that exists to be crawled thousands of times.
 *
 * The slug is written in PHP with the same `Str::slug()` used to build the
 * links. Doing it in SQL would mean reimplementing transliteration in Postgres:
 * `Str::slug()` folds "Kärcher" to "karcher" and `lower(replace(...))` does not,
 * so the link and the lookup would disagree and every Kärcher link would 404.
 */
class BrandStats
{
    /**
     * @return int how many brands the market now has stats for
     */
    public function refresh(Market $market): int
    {
        $rows = $this->aggregate($market);

        if ($rows === []) {
            return 0;
        }

        $total = array_sum(array_map(fn (array $row) => $row['product_count'], $rows));
        $now = now();

        foreach (array_chunk($rows, 200) as $chunk) {
            $payload = [];

            foreach ($chunk as $row) {
                $payload[] = [
                    'market' => $market->value,
                    'brand' => $row['brand'],
                    'slug' => Str::slug((string) $row['brand']),
                    'product_count' => $row['product_count'],
                    'merchant_count' => $row['merchant_count'],
                    // Share of the market's catalogue, for ordering the index.
                    'share' => $total > 0 ? $row['product_count'] / $total : 0.0,
                    'min_price' => $row['min_price'],
                    'max_price' => $row['max_price'],
                    'discounted_count' => $row['discounted_count'],
                    'in_stock_count' => $row['in_stock_count'],
                    'best_discount_percent' => $row['best_discount_percent'],
                    'top_merchant_id' => $row['top_merchant_id'],
                    'top_category' => $row['top_category'],
                    'computed_at' => $now,
                ];
            }

            BrandStat::query()->upsert($payload, ['market', 'brand'], [
                'slug', 'product_count', 'merchant_count', 'share',
                'min_price', 'max_price', 'discounted_count', 'in_stock_count',
                'best_discount_percent', 'top_merchant_id', 'top_category', 'computed_at',
            ]);
        }

        /*
         * Brands that have left the catalogue keep their row, with counts at
         * zero, rather than being deleted. `pageworthy` then excludes them, so
         * the page 404s — but the row remains as evidence for anyone asking why
         * a URL that used to work no longer does. Deleting makes that
         * unanswerable, and a brand often comes back with the next feed.
         */
        $present = array_map(fn (array $row) => $row['brand'], $rows);

        BrandStat::query()
            ->forMarket($market)
            ->whereNotIn('brand', $present)
            ->update([
                'product_count' => 0,
                'merchant_count' => 0,
                'discounted_count' => 0,
                'in_stock_count' => 0,
                'best_discount_percent' => null,
                'computed_at' => $now,
            ]);

        return count($rows);
    }

    /**
     * One pass over the market's groups.
     *
     * `discounted_count` and `best_discount_percent` repeat the arithmetic in
     * `ProductGroup::discountPercent()` — floor, never round, and only when the
     * median is above the current minimum. A badge and a sentence that disagree
     * about whether something is reduced is worse than neither.
     *
     * @return list<array<string, mixed>>
     */
    private function aggregate(Market $market): array
    {
        $discount = '((median_price - min_price)::numeric / median_price) * 100';
        $isDiscounted = 'median_price IS NOT NULL AND min_price IS NOT NULL AND median_price > 0 AND min_price < median_price';

        $rows = DB::table('product_groups')
            ->selectRaw('brand')
            ->selectRaw('count(*) AS product_count')
            ->selectRaw('max(merchant_count) AS merchant_count')
            ->selectRaw('min(min_price) AS min_price')
            ->selectRaw('max(min_price) AS max_price')
            ->selectRaw("count(*) FILTER (WHERE {$isDiscounted}) AS discounted_count")
            ->selectRaw('count(*) FILTER (WHERE in_stock) AS in_stock_count')
            ->selectRaw("floor(max(CASE WHEN {$isDiscounted} THEN {$discount} ELSE NULL END)) AS best_discount_percent")
            ->where('market', $market->value)
            ->whereNotNull('brand')
            ->where('brand', '<>', '')
            ->groupBy('brand')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'brand' => (string) $row->brand,
                'product_count' => (int) $row->product_count,
                'merchant_count' => (int) $row->merchant_count,
                'min_price' => $row->min_price === null ? null : (int) $row->min_price,
                'max_price' => $row->max_price === null ? null : (int) $row->max_price,
                'discounted_count' => (int) $row->discounted_count,
                'in_stock_count' => (int) $row->in_stock_count,
                'best_discount_percent' => $row->best_discount_percent === null ? null : (int) $row->best_discount_percent,
                'top_merchant_id' => $this->topMerchant($market, (string) $row->brand),
                'top_category' => $this->topCategory($market, (string) $row->brand),
            ];
        }

        return $out;
    }

    /**
     * The shop with the most offers for this brand.
     *
     * Read off `products` (offers), not `product_groups`, because the question
     * the copy answers is "who stocks the most of it" and one group can be sold
     * by several shops.
     */
    private function topMerchant(Market $market, string $brand): ?int
    {
        $row = DB::table('products')
            ->join('product_groups', 'product_groups.id', '=', 'products.group_id')
            ->where('product_groups.market', $market->value)
            ->where('product_groups.brand', $brand)
            ->whereNotNull('products.merchant_id')
            ->selectRaw('products.merchant_id, count(*) AS offers')
            ->groupBy('products.merchant_id')
            ->orderByDesc('offers')
            ->limit(1)
            ->first();

        return $row === null ? null : (int) $row->merchant_id;
    }

    private function topCategory(Market $market, string $brand): ?string
    {
        $row = DB::table('product_groups')
            ->where('market', $market->value)
            ->where('brand', $brand)
            ->whereNotNull('category')
            ->selectRaw('category, count(*) AS n')
            ->groupBy('category')
            ->orderByDesc('n')
            ->limit(1)
            ->first();

        return $row === null ? null : (string) $row->category;
    }
}
