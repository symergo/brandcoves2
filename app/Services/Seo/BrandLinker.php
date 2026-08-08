<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\Market;
use App\Models\BrandStat;

/**
 * Brand name → brand page URL, but only when the page exists.
 *
 * ## Why this is not `Str::slug()` at the call site
 *
 * Two reasons, and both are the kind of bug you ship without noticing.
 *
 * 1. **Not every brand has a page.** `BrandStat::pageworthy()` requires three
 *    products, because a page of copy about a brand with one product on it is
 *    filler and publishing thousands of those is what gets a domain discounted.
 *    Slugifying at the call site links confidently to a 404 — from every search
 *    result, which is the worst possible place to do it.
 *
 * 2. **The slug must be the stored one.** `brand_stats.slug` was written by
 *    `Str::slug()` during the nightly refresh. Recomputing it agrees today and
 *    stops agreeing the moment a brand is renamed in a feed, at which point the
 *    link points at the new slug and the row still holds the old one.
 *
 * One query per page, keyed by brand name. Small enough that the alternative —
 * eager-loading a relation onto every group — would cost more.
 */
class BrandLinker
{
    /** @var array<string, array<string, string>> market => (lowered brand => url) */
    private array $cache = [];

    /**
     * Brands already looked up, whether or not they had a page.
     *
     * Separate from `$cache` because a brand with no page must still count as
     * answered. Tracked in `$cache` alone, an unpageworthy brand would be
     * re-queried on every call — and a search results page has plenty of them.
     *
     * @var array<string, array<string, true>>
     */
    private array $asked = [];

    /**
     * Resolve a batch of brand names at once.
     *
     * Batched because the caller is always a page of results, and doing this per
     * card is the N+1 that makes people conclude brand links are expensive.
     *
     * @param  list<string|null>  $brands
     * @return array<string, string> lowered brand name => URL
     */
    public function urls(array $brands, Market $market): array
    {
        $wanted = [];

        foreach ($brands as $brand) {
            if (is_string($brand) && trim($brand) !== '') {
                $wanted[mb_strtolower($brand)] = trim($brand);
            }
        }

        if ($wanted === []) {
            return [];
        }

        $known = $this->cache[$market->value] ?? [];
        $asked = $this->asked[$market->value] ?? [];
        $missing = array_diff_key($wanted, $asked);

        if ($missing !== []) {
            $rows = BrandStat::query()
                ->forMarket($market)
                ->pageworthy()
                ->whereIn('brand', array_values($missing))
                ->pluck('slug', 'brand');

            foreach ($rows as $brand => $slug) {
                $known[mb_strtolower((string) $brand)] = '/'.$market->value.'/brand/'.$slug;
            }

            foreach (array_keys($missing) as $lowered) {
                $asked[$lowered] = true;
            }

            $this->cache[$market->value] = $known;
            $this->asked[$market->value] = $asked;
        }

        return array_intersect_key($known, $wanted);
    }

    /** A single brand, for a caller that genuinely only has one. */
    public function url(?string $brand, Market $market): ?string
    {
        if ($brand === null) {
            return null;
        }

        return $this->urls([$brand], $market)[mb_strtolower(trim($brand))] ?? null;
    }
}
