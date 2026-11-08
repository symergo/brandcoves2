<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Models\BrandStat;
use App\Models\ProductGroup;
use App\Services\Pages\Context\PageContext;
use App\Services\Pages\Context\ResultCategories;
use App\Services\Seo\BrandLinker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Other brands that make this kind of product (owner, 2026-11-08: "add a
 * section on other brands for this kind of product").
 *
 * "This kind" is the three categories most of these results carry
 * (`ResultCategories::top()`). The brands are taken in turns from each, the
 * ones with the most products in it in this market first (brand_stats keeps
 * each brand's top categories with a count), less every brand already in the
 * results. Linked to their brand pages, so
 * only a brand that has a page appears.
 *
 * Cached for ten minutes per market and set of categories: many searches share a
 * category, and brand_stats changes once a night.
 */
final class OtherBrandLinks implements PlaceholderFunction
{
    private const LIMIT = 6;

    private const TTL = 600;

    public function name(): string
    {
        return 'other_brand_links';
    }

    public function label(): string
    {
        return 'Other brands of this kind of product, linked';
    }

    public function help(): string
    {
        return 'Brands with the most products in the main category of these results, leaving out the brands already in them, at most six, each linked to its brand page. Empty when there are none, which hides the block.';
    }

    public function level(): Level
    {
        return Level::Inline;
    }

    public function absent(): Absence
    {
        return Absence::Blank;
    }

    public function sample(): Value
    {
        return Value::links([
            ['label' => 'Bose', 'url' => '#'],
            ['label' => 'Sennheiser', 'url' => '#'],
        ]);
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function resolve(PageContext $context): Value
    {
        $present = [];

        foreach ($context->items as $group) {
            if ($group instanceof ProductGroup && filled($group->brand)) {
                $present[Str::slug($group->brand)] = true;
            }
        }

        // The three categories most of the results belong to, not only the
        // first (owner, 2026-11-08: "broaden the list"). "lenovo" is tablets,
        // laptops and desktops, and one category named only tablet brands.
        $categories = ResultCategories::top($context->items, 3);

        if ($categories === []) {
            return Value::nothing();
        }

        $byCategory = Cache::remember(
            'other-brands:'.$context->market->value.':'.md5(implode('|', $categories)),
            self::TTL,
            fn (): array => self::brandsIn($context, $categories),
        );

        /*
         * In turns: the strongest brand of the first category, then of the
         * second, then of the third, and round again. Summing the counts let
         * the biggest category take every place, which is the narrow list
         * this replaced.
         */
        $ordered = [];
        $seen = $present;

        for ($round = 0; $round < 30; $round++) {
            foreach ($byCategory as $brands) {
                $brand = $brands[$round] ?? null;

                if ($brand !== null && ! isset($seen[$brand['slug']])) {
                    $seen[$brand['slug']] = true;
                    $ordered[] = $brand['brand'];
                }
            }
        }

        if ($ordered === []) {
            return Value::nothing();
        }

        $urls = app(BrandLinker::class)->urls($ordered, $context->market);
        $links = [];

        foreach ($ordered as $brand) {
            $url = $urls[mb_strtolower($brand)] ?? null;

            if ($url !== null) {
                $links[] = ['label' => $brand, 'url' => $url];
            }

            if (count($links) === self::LIMIT) {
                break;
            }
        }

        return Value::links($links);
    }

    /**
     * Per category, its brands ranked by their products in that category, not
     * overall: a brand with one phone case and ten thousand other things is
     * not a phone-case brand. One query for all three categories.
     *
     * @param  list<string>  $categories
     * @return array<string, list<array{brand: string, slug: string}>>
     */
    private static function brandsIn(PageContext $context, array $categories): array
    {
        $stats = BrandStat::query()
            ->forMarket($context->market)
            ->where(function ($query) use ($categories): void {
                foreach ($categories as $category) {
                    $query->orWhereJsonContains('categories', [['category' => $category]]);
                }
            })
            ->orderByDesc('product_count')
            ->limit(90)
            ->get(['brand', 'slug', 'categories']);

        $out = [];

        foreach ($categories as $category) {
            $out[$category] = $stats
                ->map(fn (BrandStat $stat) => [
                    'brand' => $stat->brand,
                    'slug' => $stat->slug,
                    'count' => (int) (collect($stat->categories)->firstWhere('category', $category)['count'] ?? 0),
                ])
                ->filter(fn (array $row) => $row['count'] > 0)
                ->sortByDesc('count')
                ->map(fn (array $row) => ['brand' => $row['brand'], 'slug' => $row['slug']])
                ->values()
                ->all();
        }

        return $out;
    }
}
