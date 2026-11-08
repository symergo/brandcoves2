<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Models\BrandStat;
use App\Models\ProductGroup;
use App\Services\Pages\Context\PageContext;
use App\Services\Seo\BrandLinker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Other brands that make this kind of product (owner, 2026-11-08: "add a
 * section on other brands for this kind of product").
 *
 * "This kind" is the category most of these results carry. The brands are the
 * ones with the most products in that category in this market (brand_stats
 * keeps each brand's top categories with a count), less every brand already in
 * the results: `:brand_links` names those. Linked to their brand pages, so
 * only a brand that has a page appears.
 *
 * Cached for ten minutes per market and category: many searches share a
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
        $categories = [];
        $present = [];

        foreach ($context->items as $group) {
            if (! $group instanceof ProductGroup) {
                continue;
            }

            if (filled($group->category)) {
                $categories[$group->category] = ($categories[$group->category] ?? 0) + 1;
            }

            if (filled($group->brand)) {
                $present[Str::slug($group->brand)] = true;
            }
        }

        if ($categories === []) {
            return Value::nothing();
        }

        arsort($categories);
        $category = (string) array_key_first($categories);

        $candidates = Cache::remember(
            'other-brands:'.$context->market->value.':'.md5($category),
            self::TTL,
            fn (): array => BrandStat::query()
                ->forMarket($context->market)
                ->whereJsonContains('categories', [['category' => $category]])
                ->orderByDesc('product_count')
                ->limit(30)
                ->get(['brand', 'slug', 'categories'])
                // Ranked by their products in this category, not overall: a
                // brand with one phone case and ten thousand other things is
                // not a phone-case brand.
                ->map(fn (BrandStat $stat) => [
                    'brand' => $stat->brand,
                    'slug' => $stat->slug,
                    'count' => (int) (collect($stat->categories)->firstWhere('category', $category)['count'] ?? 0),
                ])
                ->sortByDesc('count')
                ->values()
                ->all(),
        );

        $others = array_values(array_filter($candidates, fn (array $c) => ! isset($present[$c['slug']])));

        if ($others === []) {
            return Value::nothing();
        }

        $urls = app(BrandLinker::class)->urls(array_column($others, 'brand'), $context->market);
        $links = [];

        foreach ($others as $other) {
            $url = $urls[mb_strtolower($other['brand'])] ?? null;

            if ($url !== null) {
                $links[] = ['label' => $other['brand'], 'url' => $url];
            }

            if (count($links) === self::LIMIT) {
                break;
            }
        }

        return Value::links($links);
    }
}
