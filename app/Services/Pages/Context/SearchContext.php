<?php

declare(strict_types=1);

namespace App\Services\Pages\Context;

use App\Enums\Market;
use App\Models\ProductGroup;

/**
 * What a search results page can say about itself.
 *
 * Lifted almost unchanged from `PageNarrative::facts()`, which was good, tested
 * and market-aware — the arithmetic is not the part that needed replacing.
 */
final class SearchContext extends PageContext
{
    /**
     * @param  list<ProductGroup>  $items
     */
    public function __construct(
        Market $market,
        array $items,
        int $total,
        public readonly string $term,
    ) {
        // The rotation key is the page's identity, so two searches reliably draw
        // different phrasings from the same set.
        parent::__construct($market, $items, $total, $term);
    }

    public function page(): string
    {
        return 'search';
    }

    /**
     * The word is **added** to the query, never substituted.
     *
     * `?q=koptelefoon over-ear`, not `?q=over-ear`. The suggestions come from
     * the titles that survived this search, so every one of them is a word the
     * current result set can still answer and the path cannot dead-end in zero
     * results. Widening is what the search box is for.
     */
    public function narrowUrl(string $term): string
    {
        return '/'.$this->market->value.'/search?'.http_build_query([
            'q' => trim($this->term.' '.$term),
        ]);
    }

    protected function computeFacts(): array
    {
        $prices = $this->prices();
        $discounts = $this->discounts();

        return [
            'term' => $this->term,
            // The only number here that describes the catalogue rather than the
            // page. Every other fact is checkable against the grid above it.
            'count' => $this->number($this->total),
            'shown' => count($this->items),
            'shops' => $this->shops(),
            'comparable' => $this->comparable(),
            'reduced' => count($discounts),
            'percent' => $discounts === [] ? 0 : max($discounts),
            'low' => $prices === [] ? '' : $this->money(min($prices)),
            'high' => $prices === [] ? '' : $this->money(max($prices)),
            // Six, because a sentence naming more than that has stopped being a
            // sentence and become a list.
            'brands' => implode(', ', array_slice($this->brands(), 0, 6)),
        ];
    }

    protected function computeConditions(): array
    {
        return [
            'has_prices' => $this->prices() !== [],
            'has_discount' => $this->discounts() !== [],
            'multi_shop' => $this->comparable() > 0,
            'has_brands' => $this->brands() !== [],
        ];
    }
}
