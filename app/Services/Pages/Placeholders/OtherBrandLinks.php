<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Models\ProductGroup;
use App\Services\Pages\Context\PageContext;
use App\Services\Pages\Context\ResultCategories;
use App\Services\Seo\BrandLinker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Other brands that make this kind of product (owner, 2026-11-08: "add a
 * section on other brands for this kind of product").
 *
 * "This kind" is the three categories most of these results carry
 * (`ResultCategories::significant()`). The brands are taken in turns from
 * each, the ones with the most in-stock products in it in this market first,
 * less every brand already in the results and their sub-brands. Linked to their brand pages, so
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
        $categories = ResultCategories::significant($context->items, 3);

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
        // Seen by the folded name, so a brand in the results is never offered
        // back under another spelling.
        $seen = [];
        foreach (array_keys($present) as $own) {
            $seen[self::fold($own)] = true;
        }

        for ($round = 0; $round < 30; $round++) {
            foreach ($byCategory as $brands) {
                $brand = $brands[$round] ?? null;

                if ($brand !== null && ! isset($seen[self::fold($brand['slug'])]) && ! self::sameFamily($brand['slug'], $present)) {
                    $seen[self::fold($brand['slug'])] = true;
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

    /** A slug without its hyphens: "black-decker" and "blackdecker" are one brand. */
    private static function fold(string $slug): string
    {
        return str_replace('-', '', $slug);
    }

    /**
     * "Stanley Fatmax" is Stanley, not an alternative to it: a slug that
     * extends a brand in the results, or that one extends, is the same family.
     *
     * @param  array<string, true>  $present
     */
    private static function sameFamily(string $slug, array $present): bool
    {
        foreach (array_keys($present) as $own) {
            if (str_starts_with($slug, $own.'-') || str_starts_with($own, $slug.'-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Per category, its brands ranked by their in-stock products in it, read
     * from the products themselves. One grouped query on the category index
     * (`product_groups_category_rail_idx`), about 25 ms for the largest
     * category on a cold cache, then cached.
     *
     * Not from `brand_stats.categories`, which it used first: that keeps each
     * brand's four biggest categories only, so DeWalt and Makita, which sell
     * tool cases among a great deal else, never appeared beside Stanley.
     *
     * @param  list<string>  $categories
     * @return array<string, list<array{brand: string, slug: string}>>
     */
    private static function brandsIn(PageContext $context, array $categories): array
    {
        $rows = DB::table('product_groups')
            ->where('market', $context->market->value)
            // The index's own conditions, so the planner can use it, and only
            // what can be bought.
            ->where('worth_showing', true)
            ->where('in_stock', true)
            ->whereIn('category', $categories)
            ->whereNotNull('brand')
            ->groupBy('category', 'brand')
            ->selectRaw('category, brand, count(*) as n')
            ->get();

        $out = array_fill_keys($categories, []);

        foreach ($rows as $row) {
            $slug = Str::slug((string) $row->brand);

            if ($slug === '') {
                continue;
            }

            // Spellings of one brand fold into one: "Audio-Technica" and
            // "Audio Technica" by their slug, and "BLACK + DECKER" and
            // "BLACK+DECKER" (black-decker, blackdecker) by the slug without
            // its hyphens; both showed on production. The most common spelling
            // is the one shown, and it is that spelling's page that is linked.
            $key = self::fold($slug);
            $entry = $out[$row->category][$key] ?? ['brand' => (string) $row->brand, 'slug' => $slug, 'n' => 0, 'top' => 0];
            $entry['n'] += (int) $row->n;

            if ((int) $row->n > $entry['top']) {
                $entry['brand'] = (string) $row->brand;
                $entry['slug'] = $slug;
                $entry['top'] = (int) $row->n;
            }

            $out[$row->category][$key] = $entry;
        }

        foreach ($out as $category => $brands) {
            uasort($brands, fn (array $a, array $b) => $b['n'] <=> $a['n']);
            $out[$category] = array_values(array_map(
                fn (array $b) => ['brand' => $b['brand'], 'slug' => $b['slug']],
                array_slice($brands, 0, 30),
            ));
        }

        return $out;
    }
}
