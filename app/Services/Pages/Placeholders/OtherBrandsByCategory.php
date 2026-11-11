<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Services\Pages\Context\PageContext;
use App\Services\Pages\Context\ResultCategories;
use App\Services\Seo\BrandLinker;

/**
 * Other brands, grouped by the kinds of product in these results (owner,
 * 2026-11-11). The search "custom" found Hot Wheels cars, car speakers,
 * construction toys and MIDI controllers, and the section read "Merken zoals
 * Hot Wheels ... Bekijk dan de aanbiedingen van Lego, JBL, Geomag, Hercules":
 * one brand named as the subject of a page it did not define, and brands of
 * four unrelated kinds of product in one list. The owner asked for
 *
 *     Andere merken
 *     Autospeaker: JBL, Pioneer
 *     Constructiespeelgoed: LEGO, Geomag, Mega Bloks
 *     Midi controller: Hercules
 *
 * - **Which categories**: the significant ones of these results
 *   (`ResultCategories::significant()`, at most three), labelled only when the
 *   name prints as plain words (`ResultCategories::label()`); a feed category
 *   like "LEGO (R) Konstruktionsspielzeuge" is skipped rather than printed.
 * - **Which brands**: the same as `:other_brand_links`, from the same cached
 *   query: most in-stock products in that category first, never a brand already
 *   in the results or one of its sub-brands, never the same brand under two
 *   categories, and only brands with a brand page, each linked to it.
 * - **Block-level**: a list, alone in its paragraph. Empty hides the block.
 */
final class OtherBrandsByCategory implements PlaceholderFunction
{
    private const PER_CATEGORY = 4;

    public function name(): string
    {
        return 'other_brands_by_category';
    }

    public function label(): string
    {
        return 'Other brands, one line per kind of product';
    }

    public function help(): string
    {
        return 'For each main kind of product in these results (at most three), the brands with the most products of that kind, leaving out the brands already in the results, at most four per line, each linked to its brand page. A list on its own: alone in its paragraph. Empty when there are none, which hides the block.';
    }

    public function level(): Level
    {
        return Level::Block;
    }

    public function absent(): Absence
    {
        return Absence::Blank;
    }

    public function sample(): Value
    {
        return Value::groups([
            ['label' => 'Koptelefoon', 'links' => [['label' => 'Bose', 'url' => '#'], ['label' => 'Sennheiser', 'url' => '#']]],
            ['label' => 'Speaker', 'links' => [['label' => 'JBL', 'url' => '#']]],
        ]);
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function resolve(PageContext $context): Value
    {
        $categories = ResultCategories::significant($context->items, 3);

        if ($categories === []) {
            return Value::nothing();
        }

        $byCategory = OtherBrandLinks::brandsByCategory($context, $categories);
        $present = OtherBrandLinks::present($context);
        $seen = [];

        foreach (array_keys($present) as $own) {
            $seen[OtherBrandLinks::fold($own)] = true;
        }

        // Pick per category first, so a brand strong in two categories lands
        // in the first (the larger share of the results) and only there.
        $picked = [];

        foreach ($categories as $category) {
            $label = ResultCategories::label($category, $context->items);

            if ($label === null) {
                continue;
            }

            foreach ($byCategory[$category] ?? [] as $brand) {
                $key = OtherBrandLinks::fold($brand['slug']);

                if (isset($seen[$key]) || OtherBrandLinks::sameFamily($brand['slug'], $present)) {
                    continue;
                }

                $seen[$key] = true;
                $picked[$label][] = $brand['brand'];

                // A few spare, for brands that turn out to have no page.
                if (count($picked[$label]) >= self::PER_CATEGORY * 2) {
                    break;
                }
            }
        }

        if ($picked === []) {
            return Value::nothing();
        }

        $urls = app(BrandLinker::class)->urls(array_merge(...array_values($picked)), $context->market);
        $groups = [];

        foreach ($picked as $label => $brands) {
            $links = [];

            foreach ($brands as $brand) {
                $url = $urls[mb_strtolower($brand)] ?? null;

                if ($url !== null) {
                    $links[] = ['label' => $brand, 'url' => $url];
                }

                if (count($links) === self::PER_CATEGORY) {
                    break;
                }
            }

            if ($links !== []) {
                $groups[] = ['label' => (string) $label, 'links' => $links];
            }
        }

        return Value::groups($groups);
    }
}
