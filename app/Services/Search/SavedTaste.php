<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\WishlistItem;
use App\Support\Owner;

/**
 * What somebody's lists say they like: the brands and categories of the
 * products they saved, and the products themselves so they are not shown
 * again.
 *
 * Read for the search landing (owner's request, 2026-09-13): `/search` with
 * no term showed everybody the same catalogue grid, led by whatever had the
 * most shops. For a visitor who has saved things, that grid is the one place
 * on the site that knows nothing about them. This is the taste the grid is
 * seeded from instead — see {@see SearchService::similarTo()}.
 *
 * Deliberately shallow. Brand and category are the two facts every product
 * group carries and the two a shopper would name themselves ("more Sony",
 * "more headphones"); anything cleverer would be a recommender, with its own
 * data, its own drift and its own explanations owed. The counts decide the
 * order, so the brand saved three times leads the one saved once.
 *
 * Across markets: the saved product's market does not matter, a brand is a
 * brand on either side of the border, so a list built on `be-nl` seeds the
 * `nl-nl` landing too. The products themselves are excluded by identity key
 * for the same reason: the Dutch twin of a saved Belgian product is still
 * something already on the list.
 */
final readonly class SavedTaste
{
    /** Enough of each to have variety, few enough to still look chosen. */
    private const TOP = 5;

    /**
     * @param  list<string>  $brands  most saved first
     * @param  list<string>  $categories  most saved first
     * @param  list<string>  $identityKeys  what is already on a list
     */
    private function __construct(
        public array $brands,
        public array $categories,
        public array $identityKeys,
    ) {}

    /**
     * Null when there is nothing to go on: no account, or no catalogue
     * product on any list.
     */
    public static function forOwner(Owner $owner): ?self
    {
        if (! $owner->isSignedIn()) {
            return null;
        }

        $groups = WishlistItem::query()
            ->whereNotNull('group_id')
            ->whereNotNull('accepted_at')
            ->whereHas('wishlist', fn ($q) => $owner->scope($q))
            ->with('group:id,brand,category,identity_key')
            ->get(['id', 'group_id', 'wishlist_id'])
            ->pluck('group')
            ->filter();

        if ($groups->isEmpty()) {
            return null;
        }

        $top = fn (string $column): array => $groups
            ->pluck($column)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(self::TOP)
            ->values()
            ->all();

        $brands = $top('brand');
        $categories = $top('category');

        if ($brands === [] && $categories === []) {
            return null;
        }

        return new self(
            brands: $brands,
            categories: $categories,
            identityKeys: $groups->pluck('identity_key')->unique()->values()->all(),
        );
    }
}
