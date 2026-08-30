<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Models\ProductGroup;
use App\Services\Pages\Context\PageContext;
use App\Services\Seo\BrandLinker;

/**
 * The brands in these results, each linked to its brand page.
 *
 * The linked twin of `:brands`, which says the same names as plain words. Both
 * exist because they are different sentences: "Merken hier zijn onder meer Sony,
 * Philips en JBL" reads fine unlinked in the middle of a paragraph about
 * something else, and reads better linked when the paragraph is about choosing
 * between them.
 *
 * ## Only brands that have a page
 *
 * `BrandLinker` answers that question and this defers to it entirely. Slugifying
 * a brand name at the call site links confidently to a 404 from a paragraph on
 * every search page, which is the worst possible place to do it — a brand needs
 * three products before it earns a page, because a page about a brand with one
 * product on it is filler.
 *
 * A brand without a page is therefore dropped from the list rather than rendered
 * unlinked. Half a list of links and half a list of words is a worse sentence
 * than a shorter list.
 */
final class BrandLinks implements PlaceholderFunction
{
    public function name(): string
    {
        return 'brand_links';
    }

    public function label(): string
    {
        return 'Brands here, linked';
    }

    public function help(): string
    {
        return 'The brands in these results, each linked to its own page. Brands with no page of their own are left out.';
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
            ['label' => 'Sony', 'url' => '#'],
            ['label' => 'Philips', 'url' => '#'],
            ['label' => 'JBL', 'url' => '#'],
        ]);
    }

    public function dependsOn(): array
    {
        // Reads the products directly rather than a precomputed fact.
        return [];
    }

    public function resolve(PageContext $context): Value
    {
        $brands = [];

        foreach ($context->items as $group) {
            if ($group instanceof ProductGroup && $group->brand !== null && trim($group->brand) !== '') {
                $brands[mb_strtolower($group->brand)] = $group->brand;
            }
        }

        if ($brands === []) {
            return Value::nothing();
        }

        $urls = app(BrandLinker::class)->urls(array_values($brands), $context->market);

        $links = [];

        foreach ($brands as $lowered => $name) {
            if (isset($urls[$lowered])) {
                // Six, because past that a sentence has stopped being a sentence
                // and become a list — the same cap `:brands` uses.
                $links[] = ['label' => $name, 'url' => $urls[$lowered]];
            }

            if (count($links) === 6) {
                break;
            }
        }

        return Value::links($links);
    }
}
