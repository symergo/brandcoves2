<?php

declare(strict_types=1);

namespace App\Services\Pages\Context;

use App\Models\ProductGroup;
use Illuminate\Support\Str;

/**
 * The categories most of a results page belongs to.
 *
 * Read from the products already on the page, so it costs no query: the
 * search copy names them ("Zoek je alternatieve merken voor Lenovo (tablet,
 * laptop)?") and `:other_brand_links` draws its brands from them (owner,
 * 2026-11-08: "broaden the list", and "mention the categories if that makes
 * sense and is feasible without adding cost to the page rendering").
 *
 * ## Which names may be shown
 *
 * A category is the feed's own wording, and feeds are uneven: "Tablet" and
 * "Koptelefoon" from one, "Samsung hoesjes" or "LEGO (R) Konstruktionsspielzeuge"
 * from another. `shown()` keeps a name only when it reads as a plain word or
 * two: letters, spaces and hyphens, at most 30 characters, three words, and
 * no brand from these results in it. After the first, a category also needs
 * two of the results. Anything else is still used to find
 * brands, and simply not printed.
 */
final class ResultCategories
{
    /**
     * The most common categories here, most first.
     *
     * @param  list<ProductGroup>  $items
     * @return list<string>
     */
    public static function top(array $items, int $limit = 3): array
    {
        $counts = [];

        foreach ($items as $group) {
            if ($group instanceof ProductGroup && filled($group->category)) {
                $counts[$group->category] = ($counts[$group->category] ?? 0) + 1;
            }
        }

        // Stable for equal counts, so a page names the same categories on
        // every render.
        uksort($counts, fn (string $a, string $b) => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return array_slice(array_keys($counts), 0, $limit);
    }

    /**
     * The top categories fit to print, written as words inside a sentence.
     *
     * @param  list<ProductGroup>  $items
     * @return list<string>
     */
    public static function shown(array $items, int $limit = 3): array
    {
        $brands = [];

        foreach ($items as $group) {
            if ($group instanceof ProductGroup && filled($group->brand)) {
                $brands[] = Str::slug($group->brand);
            }
        }

        $shown = [];
        $counts = array_count_values(array_values(array_filter(array_map(
            fn ($group) => $group instanceof ProductGroup && filled($group->category) ? $group->category : null,
            $items,
        ))));

        foreach (self::top($items, $limit) as $index => $category) {
            // Only a category that holds a real share of the page: "lenovo"
            // found one all-in-one among eight tablets, and naming it beside
            // tablets overstated it. The first is always named.
            if ($index > 0 && ($counts[$category] ?? 0) < 2) {
                continue;
            }

            $slug = Str::slug($category);
            $plain = preg_match('/^[\p{L}][\p{L} \-]*$/u', $category) === 1
                && mb_strlen($category) <= 30
                && count(preg_split('/\s+/', trim($category)) ?: []) <= 3;
            $branded = array_filter($brands, fn (string $brand) => $brand !== '' && str_contains($slug, $brand)) !== [];

            if ($plain && ! $branded) {
                $shown[] = self::inSentence($category);
            }
        }

        return array_values(array_unique($shown));
    }

    /**
     * "Tablet" is "tablet" mid-sentence; "TV" and "iPad" keep their shape.
     */
    private static function inSentence(string $category): string
    {
        $second = mb_substr($category, 1, 1);

        return $second !== '' && mb_strtolower($second) === $second
            ? mb_strtolower(mb_substr($category, 0, 1)).mb_substr($category, 1)
            : $category;
    }
}
