<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

/**
 * Every `:name` a block may contain.
 *
 * ## The mechanism, stated once
 *
 * Adding a placeholder later is four steps and no more:
 *
 *  1. a class implementing {@see PlaceholderFunction} — or, for a scalar, one
 *     more `new Fact(...)` in {@see Fact::all()};
 *  2. one line in {@see self::FUNCTIONS};
 *  3. its name in the `placeholders` list of every region that offers it;
 *  4. if it needs a fact nobody precomputes, one line in that page's
 *     `PageContext`.
 *
 * No migration, no schema change, no admin change — the editor's palette is
 * rendered from this registry, so it appears there on its own — and every block
 * already written can use it the day it ships. The only thing that costs more is
 * a function returning a shape {@see Value} does not have yet, which is one
 * branch in `Parts.tsx` as well.
 *
 * ## An explicit list, never auto-discovery
 *
 * Scanning the directory would be shorter and would fail silently: rename a
 * class and the placeholder simply stops existing, while every block still
 * naming it quietly disappears from the site. Listed here, the same rename is a
 * "class not found" at boot.
 */
final class PlaceholderRegistry
{
    /**
     * The functions that do something beyond reading a fact.
     *
     * @var list<class-string<PlaceholderFunction>>
     */
    private const FUNCTIONS = [
        BrandLinks::class,
        TermLinks::class,
        RelatedSearches::class,
    ];

    /** @var array<string, PlaceholderFunction>|null */
    private static ?array $memo = null;

    /**
     * Every function, keyed by name.
     *
     * Built once per process. Safe as static state where a database-backed
     * registry would not be: this list is code, so it cannot change between
     * requests, and a process holding a stale copy of it is a process running
     * stale code.
     *
     * @return array<string, PlaceholderFunction>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        // Three families of one-class-many-instances, then the ones that each
        // do something different enough to be their own class.
        $all = [...Fact::all(), ...SiteLink::all(), ...SubjectLink::all()];

        foreach (self::FUNCTIONS as $class) {
            $function = new $class;
            $all[$function->name()] = $function;
        }

        return self::$memo = $all;
    }

    public static function find(string $name): ?PlaceholderFunction
    {
        return self::all()[$name] ?? null;
    }

    /**
     * The functions a region offers, in the order it named them.
     *
     * A name with no function behind it is dropped rather than throwing: the
     * region list and this registry are two places that have to agree, and
     * `PageRegionsTest` is where that disagreement is reported. Throwing here
     * would turn a typo in a region declaration into a white page.
     *
     * @param  list<string>  $names
     * @return list<PlaceholderFunction>
     */
    public static function forNames(array $names): array
    {
        return array_values(array_filter(array_map(self::find(...), $names)));
    }

    /**
     * The placeholder names a body uses, whether or not they are allowed.
     *
     * Matches `:name` but not a bare colon or a time like `09:15`, because a
     * false positive here becomes a validation error an editor cannot explain.
     *
     * @return list<string>
     */
    public static function namesIn(string $body): array
    {
        preg_match_all('/:([a-z][a-z0-9_]*)/', $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /** Reset the memo. Tests only — the list is code and does not change at runtime. */
    public static function fake(?array $functions): void
    {
        self::$memo = $functions;
    }
}
