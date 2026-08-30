<?php

declare(strict_types=1);

namespace App\Services\Pages\Context;

use App\Enums\Market;
use App\Models\ProductGroup;

/**
 * What a brand page can say about itself.
 *
 * The reader typed a brand name, so the facts a brand page has that a search
 * page does not are all about the brand's shape in this market: what it makes
 * here, which shop carries most of it.
 */
final class BrandContext extends PageContext
{
    /**
     * @param  list<ProductGroup>  $items
     * @param  list<string>  $categories  what the brand appears in, most first
     */
    public function __construct(
        Market $market,
        array $items,
        int $total,
        public readonly string $brand,
        public readonly string $slug = '',
        public readonly ?string $topShop = null,
        public readonly ?string $topCategory = null,
        public readonly array $categories = [],
        string $rotationKey = '',
    ) {
        // The slug rather than the display name: it is the page's URL identity,
        // so two brands whose names differ only in punctuation cannot draw the
        // same phrasing on what is really one page.
        parent::__construct($market, $items, $total, $rotationKey !== '' ? $rotationKey : ($slug !== '' ? $slug : $brand));
    }

    public function page(): string
    {
        return 'brand';
    }

    /** A sub-search within this brand, never a search across the whole site. */
    public function narrowUrl(string $term): string
    {
        return '/'.$this->market->value.'/brand/'.$this->slug.'?'.http_build_query(['q' => $term]);
    }

    protected function computeFacts(): array
    {
        $prices = $this->prices();
        $discounts = $this->discounts();

        return [
            'brand' => $this->brand,
            'count' => $this->number($this->total),
            'shown' => count($this->items),
            'shops' => $this->shops(),
            'comparable' => $this->comparable(),
            'reduced' => count($discounts),
            'percent' => $discounts === [] ? 0 : max($discounts),
            'low' => $prices === [] ? '' : $this->money(min($prices)),
            'high' => $prices === [] ? '' : $this->money(max($prices)),
            'brands' => implode(', ', array_slice($this->brands(), 0, 6)),
            'shop' => $this->topShop ?? '',
            'category' => $this->topCategory ?? '',
            // Three, said as a sentence rather than a list: "koptelefoons,
            // speakers en soundbars".
            'categories' => $this->list(array_slice($this->categories, 0, 3)),
        ];
    }

    protected function computeConditions(): array
    {
        return [
            'has_prices' => $this->prices() !== [],
            'has_discount' => $this->discounts() !== [],
            'multi_shop' => $this->comparable() > 0,
            'has_top_shop' => $this->topShop !== null && $this->topShop !== '',
            'has_top_category' => $this->topCategory !== null && $this->topCategory !== '',
            'has_categories' => $this->categories !== [],
        ];
    }
}
