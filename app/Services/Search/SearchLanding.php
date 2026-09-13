<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\ProductGroup;
use App\Models\WishlistItem;
use App\Services\Seo\BrandLinker;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Support\Facades\DB;
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
     * saved products are stored under.
     *
     * ## Only brands this market sells
     *
     * Lists are per market, and a person browses in one market while their
     * lists live in another: the owner saved Melitta, Scanpart and Teltonika
     * on be-nl and opened the landing on en, where none of the three is sold.
     * No page, so the fallback search, and the search filtered on a brand the
     * market does not carry is empty (reported 2026-09-14). The chip was the
     * site's own suggestion, so an empty answer to it is the site's fault. A
     * saved brand is offered only where it has a product the search could
     * show, whichever market it was saved in; JBL saved on be-nl is still a
     * chip on en because en sells JBL.
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
            ->all();

        // One query each for the whole row: the pages this market has, keyed
        // by lowered name, and the spellings it sells the rest under.
        $pages = $this->links->urls(
            array_map(fn (string $slug) => $spellings[$slug][0], $slugs),
            $current->get(),
        );
        $sold = $this->soldHere($spellings, $current);

        $chips = [];

        foreach ($slugs as $slug) {
            $name = $spellings[$slug][0];
            $page = $pages[mb_strtolower($name)] ?? null;

            if ($page === null && ! isset($sold[$slug])) {
                continue;
            }

            $chips[] = [
                'name' => $name,
                'url' => $page ?? $current->url('search').'?'.http_build_query(['brand' => $sold[$slug]]),
            ];

            if (count($chips) === self::BRANDS) {
                break;
            }
        }

        return $chips;
    }

    /**
     * What this market sells of the saved brands: slug => the spellings its
     * products carry. The search filters on `brand` as stored, so the chip
     * must search the market's spellings, not the ones the lists happen to
     * hold ("JBL" saved on be-nl, "jbl" sold on en). Case is folded in SQL,
     * the rest of the fold (punctuation) in PHP on the way back, as it is
     * everywhere brands are compared (see brand_stats). The same floor
     * `SearchService` puts under every result, a price and an image, so a
     * spelling returned here is one the search will show something for.
     *
     * @param  array<string, list<string>>  $spellings  slug => spellings saved
     * @return array<string, list<string>> slug => spellings sold here
     */
    private function soldHere(array $spellings, CurrentMarket $current): array
    {
        if ($spellings === []) {
            return [];
        }

        $lowered = array_map('mb_strtolower', array_merge(...array_values($spellings)));

        $found = ProductGroup::query()
            ->forMarket($current->get())
            ->whereNotNull('min_price')
            ->whereNotNull('image_url')
            ->whereIn(DB::raw('lower(brand)'), $lowered)
            ->distinct()
            ->pluck('brand');

        // The list's own spellings first, in the order they were saved, so
        // the chip's URL is stable and reads like the list it came from.
        $rank = array_flip($lowered);
        $found = $found->sortBy(fn (string $brand) => $rank[mb_strtolower($brand)] ?? PHP_INT_MAX)->values();

        $sold = [];

        foreach ($found as $brand) {
            $sold[Str::slug($brand)][] = $brand;
        }

        return $sold;
    }
}
