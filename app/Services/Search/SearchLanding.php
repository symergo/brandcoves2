<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\WishlistItem;
use App\Services\Seo\BrandLinker;
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

    public function __construct(
        private readonly RecentSearches $recent,
        private readonly BrandLinker $links,
    ) {}

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
     * The brands of what this person saved, most saved first.
     *
     * A brand is a slug, not a spelling (see brand_stats): the page for
     * "Audio-Technica" and "Audio Technica" is one page, so the chips are
     * folded the same way before they are counted.
     *
     * ## Where a chip goes
     *
     * To the brand page when this market has one, and otherwise to the search
     * filtered on the brand. Not every brand has a page: `BrandStat::pageworthy()`
     * asks for three products, and a brand somebody saved one product of is
     * exactly the kind that falls short. Until 2026-09-13 the chips were built
     * by slugifying the name and trusting the page to exist, which is the bug
     * `BrandLinker` was written to prevent and the one it named as the worst
     * place to have it — a link the site offers unprompted, to a 404. "AIR&ME"
     * was the report: one saved product, no `brand_stats` row, a chip to
     * `/brand/airme`, page not found.
     *
     * The fallback is a search rather than no chip, because the visitor saved
     * something of that brand and a search on it shows them that something.
     * It filters on the spellings their own lists carry, which is what the
     * saved products are stored under, so it cannot come back empty for the
     * wrong reason.
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

        /** @var array<string, list<string>> $spellings slug => every spelling saved, first seen first */
        $spellings = [];

        foreach ($brands as $brand) {
            $slug = Str::slug($brand);

            if ($slug !== '' && ! in_array($brand, $spellings[$slug] ?? [], true)) {
                $spellings[$slug][] = $brand;
            }
        }

        $slugs = $brands
            ->countBy(fn (string $brand) => Str::slug($brand))
            ->forget('')
            ->sortDesc()
            ->keys()
            ->take(self::BRANDS)
            ->all();

        // One query for the whole row of chips, keyed by lowered name.
        $pages = $this->links->urls(
            array_map(fn (string $slug) => $spellings[$slug][0], $slugs),
            $current->get(),
        );

        $chips = [];

        foreach ($slugs as $slug) {
            $name = $spellings[$slug][0];

            $chips[] = [
                'name' => $name,
                'url' => $pages[mb_strtolower($name)]
                    ?? $current->url('search').'?'.http_build_query(['brand' => $spellings[$slug]]),
            ];
        }

        return $chips;
    }
}
