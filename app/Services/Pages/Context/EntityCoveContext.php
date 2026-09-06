<?php

declare(strict_types=1);

namespace App\Services\Pages\Context;

use App\Enums\Market;
use App\Models\ProductGroup;

/**
 * What a written brand or shop page can say about itself.
 *
 * ## The items are the sidebar, and that is the point
 *
 * Every other context is handed the products on a page of results. This page has
 * no results — an entity Cove carries no shortlist and the products beside it
 * are a live rail — so `$items` is that rail: the handful of products a reader
 * can actually see next to the writing.
 *
 * The house rule survives intact and matters more here than anywhere: a claim is
 * about what is visible. `:count` remains the exception it is everywhere else —
 * the entity's total in this market, used to say how many there are and never to
 * characterise them, which is exactly what the "see all 1,984" link needs.
 *
 * ## One class, two pages
 *
 * `page()` is a constructor argument rather than a hard-coded string, because a
 * Brand Cove and a Shop Cove are the same page shape with different words. See
 * `EntityCoveRegions` for why the words are kept apart.
 */
final class EntityCoveContext extends PageContext
{
    /**
     * @param  list<ProductGroup>  $items  the products in the sidebar, not a result page
     * @param  list<string>  $categories  what this entity sells in, most first
     */
    public function __construct(
        Market $market,
        array $items,
        int $total,
        public readonly string $page,
        public readonly string $entity,
        public readonly string $slug,
        public readonly string $searchUrl,
        public readonly array $categories = [],
    ) {
        parent::__construct($market, $items, $total, $slug);
    }

    public function page(): string
    {
        return $this->page;
    }

    /**
     * Narrowing leaves this page, deliberately.
     *
     * There is nothing here to narrow: the page is an article, not a result set,
     * so a word from the sidebar has to lead somewhere that can show more of
     * them. That is the filtered search, which is also where the sidebar's own
     * "see all" link goes — one destination for "more of this", rather than two
     * that differ by which control you used.
     */
    public function narrowUrl(string $term): string
    {
        return $this->searchUrl.(str_contains($this->searchUrl, '?') ? '&' : '?')
            .http_build_query(['q' => $term]);
    }

    protected function computeFacts(): array
    {
        $prices = $this->prices();
        $discounts = $this->discounts();

        return [
            'entity' => $this->entity,
            'count' => $this->number($this->total),
            'shown' => count($this->items),
            'shops' => $this->shops(),
            'reduced' => count($discounts),
            'percent' => $discounts === [] ? 0 : max($discounts),
            'low' => $prices === [] ? '' : $this->money(min($prices)),
            'high' => $prices === [] ? '' : $this->money(max($prices)),
            'categories' => $this->list(array_slice($this->categories, 0, 3)),
        ];
    }

    protected function computeConditions(): array
    {
        return [
            'has_prices' => $this->prices() !== [],
            'has_discount' => $this->discounts() !== [],
            'has_categories' => $this->categories !== [],
        ];
    }
}
