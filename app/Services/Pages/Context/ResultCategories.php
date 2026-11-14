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
        // int|string, and strings again on the way out: a feed category that is
        // only digits ("5121") is an integer as an array key, and every caller
        // types a category as a string.
        uksort($counts, fn (int|string $a, int|string $b) => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);

        return array_map(strval(...), array_slice(array_keys($counts), 0, $limit));
    }

    /**
     * The top categories that hold a real share of the page: the first always,
     * after it only one that two results carry. "stanley" found four tool
     * cases and one cuddly toy, and the toy's category brought toy brands into
     * "alternative brands for Stanley" (2026-11-08). Used both for the brands
     * and for the names printed, so the two always agree.
     *
     * @param  list<ProductGroup>  $items
     * @return list<string>
     */
    public static function significant(array $items, int $limit = 3): array
    {
        $counts = array_count_values(array_values(array_filter(array_map(
            fn ($group) => $group instanceof ProductGroup && filled($group->category) ? $group->category : null,
            $items,
        ))));

        return array_values(array_filter(
            self::top($items, $limit),
            fn (string $category, int $index) => $index === 0 || ($counts[$category] ?? 0) >= 2,
            ARRAY_FILTER_USE_BOTH,
        ));
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

        foreach (self::significant($items, $limit) as $category) {
            if (self::printable($category, $brands)) {
                $shown[] = self::inSentence($category);
            }
        }

        return array_values(array_unique($shown));
    }

    /**
     * A category name fit to print as a line's label ("Autospeaker"), or null.
     * The same test as `shown()`: a plain word or two, no brand from the page in it.
     *
     * @param  list<ProductGroup>  $items
     */
    public static function label(string $category, array $items): ?string
    {
        $brands = [];

        foreach ($items as $group) {
            if ($group instanceof ProductGroup && filled($group->brand)) {
                $brands[] = Str::slug($group->brand);
            }
        }

        return self::printable($category, $brands) ? Str::ucfirst(trim($category)) : null;
    }

    /** @param  list<string>  $brandSlugs */
    private static function printable(string $category, array $brandSlugs): bool
    {
        $slug = Str::slug($category);
        $plain = preg_match('/^[\p{L}][\p{L} \-]*$/u', $category) === 1
            && mb_strlen($category) <= 30
            && count(preg_split('/\s+/', trim($category)) ?: []) <= 3;
        $branded = array_filter($brandSlugs, fn (string $brand) => $brand !== '' && str_contains($slug, $brand)) !== [];

        return $plain && ! $branded;
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
