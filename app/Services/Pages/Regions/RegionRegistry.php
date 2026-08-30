<?php

declare(strict_types=1);

namespace App\Services\Pages\Regions;

/**
 * Every place on the site where an editor may put prose.
 *
 * ## Why this is code and not a table
 *
 * Two reasons, and the second is a hard blocker rather than a preference.
 *
 * A region is a *render site*: a position in a component, with a set of facts
 * that position can supply. Adding a row to a table would create a region no
 * page renders, which is precisely the state the retired `brand_intro` surface
 * was in — an admin screen offering work that silently went nowhere.
 *
 * And conditions are closures. `php artisan config:cache` runs in the Docker
 * build and dies outright on a closure in config, so config is not available
 * either.
 *
 * ## Adding a region to a new page
 *
 *  1. a class here with its regions, their placeholders and their conditions;
 *  2. one line in {@see self::PAGES};
 *  3. a `PageContext` for that page producing exactly those facts and answering
 *     exactly those conditions;
 *  4. the controller calls `PageCopy::forPage()` and passes the result as a prop;
 *  5. the page component renders it, and the SSR bundle is rebuilt.
 *
 * Then adding a *place* is a deploy and adding *text* is not, which is the whole
 * arrangement.
 *
 * ## An explicit list, never auto-discovery
 *
 * Scanning the directory would be shorter and would fail silently: rename a
 * class and its regions cease to exist, while their blocks sit in the table
 * orphaned and every page that used to carry them goes quiet. Listed here, the
 * same rename is a "class not found" at boot.
 */
final class RegionRegistry
{
    /** @var list<class-string> */
    private const PAGES = [
        SearchPageRegions::class,
        BrandPageRegions::class,
    ];

    /** @var array<string, Region>|null */
    private static ?array $memo = null;

    /**
     * Every region, keyed `page.region`.
     *
     * Static memo, which is safe here where it would not be for anything
     * database-backed: this is code, so it cannot change between requests, and a
     * process holding a stale copy of it is a process running stale code.
     *
     * @return array<string, Region>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $regions = [];

        foreach (self::PAGES as $class) {
            foreach ($class::all() as $region) {
                $regions[$region->id()] = $region;
            }
        }

        return self::$memo = $regions;
    }

    /** @return list<Region> */
    public static function forPage(string $page): array
    {
        return array_values(array_filter(
            self::all(),
            fn (Region $region) => $region->page === $page,
        ));
    }

    public static function find(string $page, string $key): ?Region
    {
        return self::all()["{$page}.{$key}"] ?? null;
    }

    /** @return list<string> */
    public static function pages(): array
    {
        return array_values(array_unique(array_map(
            fn (Region $region) => $region->page,
            self::all(),
        )));
    }

    /** Regions the guardrail insists are written in every language. */
    public static function required(): array
    {
        return array_values(array_filter(self::all(), fn (Region $r) => $r->requiresContent));
    }
}
