<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\Market;
use App\Models\Product;
use App\Models\ProductGroup;

/**
 * schema.org JSON-LD.
 *
 * For a price-comparison site this is the single highest-leverage piece of SEO
 * work. A `Product` with an `AggregateOffer` is what lets a search result show
 * "€329.99 to €349.00 from 2 sellers" directly in the listing — which is both
 * the thing we uniquely know and the thing that earns the click.
 *
 * Everything emitted here must be true of the page a visitor lands on. Marking
 * up a price we do not show, or a seller count we cannot back up, is a manual
 * action risk, not a clever growth tactic.
 */
class StructuredData
{
    /**
     * @param  list<Product>  $offers
     * @return array<string, mixed>
     */
    public static function product(ProductGroup $group, array $offers, Market $market, string $url): array
    {
        $buyable = array_values(array_filter($offers, fn (Product $o) => $o->availability->isBuyable() && $o->price !== null));

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $group->title,
            'url' => $url,
        ];

        if ($group->image_url !== null) {
            $data['image'] = $group->image_url;
        }

        if ($group->brand !== null) {
            $data['brand'] = ['@type' => 'Brand', 'name' => $group->brand];
        }

        // Only a validated GTIN-13 — the title-fallback identity key is an
        // internal string and claiming it as a barcode would be a lie.
        if ($group->identity_kind?->value === 'ean') {
            $data['gtin13'] = $group->identity_key;
        }

        if ($buyable === []) {
            // No offer at all is still worth marking up as a product, but an
            // AggregateOffer with no offers behind it would be fabricated.
            return $data;
        }

        $prices = array_map(fn (Product $o) => $o->price, $buyable);

        $data['offers'] = [
            '@type' => 'AggregateOffer',
            'priceCurrency' => $market->currency(),
            // Decimal strings: schema.org wants a price, and cents/100 as a
            // float would serialise as 329.99000000000001 often enough to matter.
            'lowPrice' => number_format(min($prices) / 100, 2, '.', ''),
            'highPrice' => number_format(max($prices) / 100, 2, '.', ''),
            'offerCount' => count($buyable),
            'availability' => 'https://schema.org/InStock',
            'offers' => array_map(fn (Product $o) => [
                '@type' => 'Offer',
                'price' => number_format((int) $o->price / 100, 2, '.', ''),
                'priceCurrency' => $o->currency,
                'availability' => 'https://schema.org/InStock',
                'seller' => ['@type' => 'Organization', 'name' => $o->merchant?->name ?? $o->source->label()],
            ], $buyable),
        ];

        return $data;
    }

    /**
     * Breadcrumbs, so a listing shows a readable path rather than a bare URL.
     *
     * @param  list<array{name: string, url: string}>  $crumbs
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $crumbs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_values(array_map(fn (int $i, array $crumb) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ], array_keys($crumbs), $crumbs)),
        ];
    }

    /**
     * A ranked list of products — a buying guide, or a day's picks.
     *
     * ItemList rather than a bare set of Products: the order is editorial and
     * saying so is the difference between "here are seven things" and "here are
     * seven things, ranked". Items carry a url and a name only; the price lives
     * on each product's own page, where it is generated from live offers rather
     * than from copy that goes stale.
     *
     * @param  list<array{name: string, url: string, image: string|null}>  $items
     * @return array<string, mixed>
     */
    public static function itemList(array $items, string $name, string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $name,
            'url' => $url,
            'numberOfItems' => count($items),
            'itemListOrder' => 'https://schema.org/ItemListOrderAscending',
            'itemListElement' => array_values(array_map(fn (int $i, array $item) => array_filter([
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item['name'],
                'url' => $item['url'],
                'image' => $item['image'] ?? null,
            ], fn ($v) => $v !== null), array_keys($items), $items)),
        ];
    }

    /**
     * FAQPage.
     *
     * Only emitted when both halves of every pair are present — a half-empty
     * Q&A renders as an invalid FAQPage and Search Console will say so.
     *
     * @param  list<array{q: string, a: string}>  $faq
     * @return array<string, mixed>
     */
    public static function faq(array $faq): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_values(array_map(fn (array $pair) => [
                '@type' => 'Question',
                'name' => $pair['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $pair['a']],
            ], $faq)),
        ];
    }

    /**
     * A brand, as an entity with a page of its own.
     *
     * Deliberately minimal: `Brand` with a name and a URL and nothing else. The
     * temptation is to add `logo`, `sameAs` or an `aggregateRating` — we have no
     * logo we are licensed to serve, no verified Wikidata mapping, and no
     * ratings at all. Structured data that asserts something unverifiable is
     * worse than none, because it is the half of the page a search engine reads
     * literally.
     *
     * @return array<string, mixed>
     */
    public static function brand(string $name, string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Brand',
            'name' => $name,
            'url' => $url,
        ];
    }

    /** @return array<string, mixed> */
    public static function website(string $url, Market $market): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => 'Brandcoves',
            'url' => $url,
            'inLanguage' => $market->hrefLang(),
            // Declares the search endpoint so a listing can offer a search box.
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $url.'/'.$market->value.'/search?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }
}
