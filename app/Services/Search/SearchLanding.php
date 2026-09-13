<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\WishlistItem;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Support\Str;

/**
 * What `/search` shows before anybody has typed anything.
 *
 * No products. The landing used to be a catalogue grid, and for a day
 * (2026-09-13) a grid seeded from the visitor's lists, with the live shops
 * asked on the way; the owner measured that as very slow and, more to the
 * point, decided a page with no question on it should not answer with
 * products at all. It offers ways in instead: the searches people made
 * lately, the brands on the visitor's own lists, and the site's other ways of
 * finding something — asking others, a shared list others add to, a gift
 * list for somebody, and the search tips.
 *
 * Only the two data-bearing parts are built here; the tools are links the
 * page knows on its own.
 */
final class SearchLanding
{
    /** Brand chips shown: enough to cover a list, few enough to be a row. */
    private const BRANDS = 8;

    public function __construct(private readonly RecentSearches $recent) {}

    /**
     * @return array{
     *     recentSearches: list<array{term: string, url: string, images: list<string>}>,
     *     brands: list<array{name: string, url: string}>,
     * }
     */
    public function for(Owner $owner, CurrentMarket $current): array
    {
        return [
            'recentSearches' => $this->recent->for($current->get()),
            'brands' => $this->brands($owner, $current),
        ];
    }

    /**
     * The brands of what this person saved, most saved first, as brand pages.
     *
     * A brand is a slug, not a spelling (see brand_stats): the page for
     * "Audio-Technica" and "Audio Technica" is one page, so the chips are
     * folded the same way before they are counted.
     *
     * @return list<array{name: string, url: string}>
     */
    private function brands(Owner $owner, CurrentMarket $current): array
    {
        if (! $owner->isSignedIn()) {
            return [];
        }

        $brands = WishlistItem::query()
            ->whereNotNull('group_id')
            ->whereNotNull('accepted_at')
            ->whereHas('wishlist', fn ($q) => $owner->scope($q))
            ->with('group:id,brand')
            ->get(['id', 'group_id', 'wishlist_id'])
            ->pluck('group.brand')
            ->filter(fn ($brand) => is_string($brand) && trim($brand) !== '')
            ->map(fn (string $brand) => trim($brand));

        $names = [];

        foreach ($brands as $brand) {
            $names[Str::slug($brand)] ??= $brand;
        }

        return $brands
            ->countBy(fn (string $brand) => Str::slug($brand))
            ->sortDesc()
            ->keys()
            ->take(self::BRANDS)
            ->map(fn (string $slug) => [
                'name' => $names[$slug],
                'url' => $current->url("brand/{$slug}"),
            ])
            ->values()
            ->all();
    }
}
