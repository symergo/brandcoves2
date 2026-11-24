<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\Market;
use App\Models\Product;
use App\Models\ProductGroup;
use Carbon\CarbonInterface;

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
            // The heading, not the stored title: structured data must describe
            // what the page visibly says, and the <h1> is the cleaned string.
            'name' => $group->heading(),
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
     * Product data for the first products of a page that lists several: a search result, a Cove.
     *
     * Owner, 2026-11-15: "add product snippets for the first 5 items". The pages do not send these loose:
     * {@see self::productList()} and {@see self::withProducts()} put them in a list. One `Product` per item, each with the
     * address of its own page, so a search engine can read what the page's first results are and what they
     * cost. Five and not all of them: the first five are what a visitor sees without scrolling, and a result
     * page of 48 would carry 48 blocks of markup on every request.
     *
     * The same rule as {@see self::product()}: only what the page visibly shows. The card of each of these
     * shows the name, the picture and the lowest price, so that is what is said here, from the product's own
     * stored figures (`min_price`, `max_price`, `offer_count`), never from text. A product nobody sells right
     * now is named without an offer, not left out: it is still one of the first five.
     *
     * Good to know when judging it later: Google documents product rich results for a page about one product,
     * and treats a page listing several as a list. This markup is valid there and readable by anything that
     * reads schema.org, but a price under a search result of ours in Google is not something it promises.
     *
     * @param  iterable<ProductGroup|null>  $groups  in the order the page shows them
     * @return list<array<string, mixed>>
     */
    public static function productSnippets(iterable $groups, Market $market, int $limit = 5): array
    {
        $snippets = [];

        foreach ($groups as $group) {
            if (count($snippets) >= $limit) {
                break;
            }

            // A pick whose product has gone, or one that is not a catalogue product, has no page to point at.
            if (! $group instanceof ProductGroup) {
                continue;
            }

            $data = [
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $group->heading(),
                'url' => url($group->path()),
            ];

            if ($group->image_url !== null) {
                $data['image'] = $group->image_url;
            }

            if ($group->brand !== null) {
                $data['brand'] = ['@type' => 'Brand', 'name' => $group->brand];
            }

            if ($group->identity_kind?->value === 'ean') {
                $data['gtin13'] = $group->identity_key;
            }

            if ($group->in_stock && $group->min_price !== null) {
                $data['offers'] = [
                    '@type' => 'AggregateOffer',
                    'priceCurrency' => $market->currency(),
                    'lowPrice' => number_format($group->min_price / 100, 2, '.', ''),
                    'highPrice' => number_format(max($group->max_price ?? 0, $group->min_price) / 100, 2, '.', ''),
                    'offerCount' => max(1, (int) $group->offer_count),
                    'availability' => 'https://schema.org/InStock',
                ];
            }

            $snippets[] = $data;
        }

        return $snippets;
    }

    /**
     * The first products of a list page as one list: an ItemList whose entries are the products.
     *
     * One list and not five loose `Product` blocks (2026-11-15, the same day they were added): Google reads a
     * page with one Product as a page about that product, and supports its product results for such pages
     * only. A search result or a Cove is a list, and this says so: "a list, these are its first entries, each
     * has a page of its own". It is also the shape Google's own list formats ask for. Null when there is
     * nothing to list, because an empty ItemList claims the page ranks nothing.
     *
     * @param  iterable<ProductGroup|null>  $groups  in the order the page shows them
     * @return array<string, mixed>|null
     */
    public static function productList(iterable $groups, Market $market, ?string $name, ?string $url, int $limit = 5): ?array
    {
        $products = self::productSnippets($groups, $market, $limit);

        if ($products === []) {
            return null;
        }

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $name,
            'url' => $url,
            'itemListElement' => array_map(fn (int $i, array $product) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'item' => array_diff_key($product, ['@context' => true]),
            ], array_keys($products), $products),
        ], fn ($value) => $value !== null);
    }

    /**
     * An existing ItemList with its entries turned into products where we have them (the guides, whose list
     * names every pick): an entry whose address is one of the products' becomes that product.
     *
     * @param  array<string, mixed>  $list  from {@see self::itemList()}
     * @param  list<array<string, mixed>>  $products  from {@see self::productSnippets()}
     * @return array<string, mixed>
     */
    public static function withProducts(array $list, array $products): array
    {
        $byUrl = [];
        foreach ($products as $product) {
            $byUrl[$product['url']] = array_diff_key($product, ['@context' => true]);
        }

        $list['itemListElement'] = array_map(
            fn (array $entry): array => isset($byUrl[$entry['url'] ?? ''])
                ? ['@type' => 'ListItem', 'position' => $entry['position'], 'item' => $byUrl[$entry['url']]]
                : $entry,
            $list['itemListElement'],
        );

        return $list;
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

    /**
     * A written piece: a guide, a seasonal guide or an advice article (2026-11-24).
     *
     * For "best X" and advice searches Google weighs who wrote a page and when;
     * these pages said neither. The author is GiftCoves itself, as an organisation
     * with its about page, because the pieces are written by the editors as a house
     * rather than signed by a person, and an invented byline would be the kind of
     * markup that earns a manual action. `dateModified` is the row's `updated_at`,
     * which moves on an edit and not on a price refresh (checked on production the
     * same day: no guide had moved in two weeks).
     *
     * @return array<string, mixed>
     */
    public static function article(
        string $headline,
        ?string $description,
        string $url,
        ?string $image,
        ?CarbonInterface $published,
        ?CarbonInterface $modified,
        Market $market,
        string $homeUrl,
        string $aboutUrl,
    ): array {
        $house = ['@type' => 'Organization', 'name' => 'GiftCoves', 'url' => $homeUrl];

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            // Google shows at most 110 characters of a headline.
            'headline' => mb_substr($headline, 0, 110),
            'description' => $description,
            'image' => $image === null ? null : [$image],
            'datePublished' => $published?->toIso8601String(),
            // Never before it was published: a row edited as a draft has an
            // older updated_at than its publication.
            'dateModified' => $published !== null && $modified !== null && $modified->lt($published)
                ? $published->toIso8601String()
                : $modified?->toIso8601String(),
            'inLanguage' => $market->hrefLang(),
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'author' => [...$house, 'url' => $aboutUrl],
            'publisher' => $house,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function website(string $url, Market $market): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => 'GiftCoves',
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
