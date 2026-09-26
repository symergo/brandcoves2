<?php

declare(strict_types=1);

namespace App\Services\PageReading;

use App\Services\Identity\Gtin;
use DOMDocument;
use DOMXPath;

/**
 * Read a product out of a shop's HTML. Pure: HTML in, a {@see PageProduct} out.
 *
 * ## In this order, and why
 *
 * 1. **JSON-LD `Product`.** What shops publish for Google's rich results, so
 *    it is the most widely present *and* the most exact: a price as a number,
 *    a currency code, a barcode. Every Shopify, WooCommerce and Magento
 *    storefront emits it by default.
 * 2. **Open Graph and `product:` meta tags.** Nearly universal for the title
 *    and the image, because every page wants a card when it is shared; a price
 *    only where the shop bothered.
 * 3. **`<title>`.** Always there. Enough to replace the host name we showed
 *    while the page was being read, and nothing more.
 *
 * Each field is taken from the first source that has it, so a page with a
 * JSON-LD price but only an Open Graph image still yields both.
 *
 * No AI. A page that says nothing structured about itself gets its title and
 * no more; guessing a price out of prose is how a list ends up showing a
 * shipping cost as the price of a present.
 */
class ProductPageParser
{
    private const MAX_TITLE = 300;

    private const MAX_DESCRIPTION = 2000;

    public function parse(string $html, string $pageUrl): ?PageProduct
    {
        if (trim($html) === '') {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // The XML declaration makes libxml read the bytes as UTF-8 rather than
        // Latin-1, which otherwise turns every é in a title into two characters.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        $ld = $this->fromJsonLd($xpath);
        $meta = $this->fromMeta($xpath);

        $title = $this->clean($ld['title'] ?? null)
            ?? $this->clean($meta['title'] ?? null)
            ?? $this->clean($xpath->evaluate('string(//title)'));

        if ($title === null) {
            return null;
        }

        $image = $this->image($ld['image'] ?? null, $pageUrl) ?? $this->image($meta['image'] ?? null, $pageUrl);

        return new PageProduct(
            title: mb_substr($title, 0, self::MAX_TITLE),
            brand: $this->clean($ld['brand'] ?? null) ?? $this->clean($meta['brand'] ?? null),
            description: ($d = $this->clean($ld['description'] ?? null) ?? $this->clean($meta['description'] ?? null)) === null
                ? null
                : mb_substr($d, 0, self::MAX_DESCRIPTION),
            imageUrl: $image,
            price: self::cents($ld['price'] ?? null) ?? self::cents($meta['price'] ?? null),
            currency: $this->currency($ld['currency'] ?? null) ?? $this->currency($meta['currency'] ?? null),
            availability: self::availability($ld['availability'] ?? null) ?? self::availability($meta['availability'] ?? null),
            gtin: Gtin::normalise(is_scalar($ld['gtin'] ?? null) ? (string) $ld['gtin'] : null),
            mpn: $this->clean($ld['mpn'] ?? null),
        );
    }

    /**
     * Money as written on a page, in cents, without ever passing a float
     * through arithmetic (invariant 7).
     *
     * "12.50", "12,50", "1.299,00", "1,299.00", "€ 12", 12.5 and 12 all come out
     * right. The decimal separator is whichever of `.` or `,` is followed by
     * exactly one or two digits at the end; everything else is a thousands
     * separator.
     */
    public static function cents(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value * 100 : null;
        }

        if (is_float($value)) {
            // JSON numbers arrive as floats. One rounding at the boundary,
            // never an accumulation, which is what the invariant is about.
            return $value >= 0 ? (int) round($value * 100) : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/[^\d.,]/', '', $value) ?? '';

        if ($digits === '' || ! preg_match('/\d/', $digits)) {
            return null;
        }

        if (preg_match('/^(.*?)[.,](\d{1,2})$/', $digits, $m)) {
            $whole = preg_replace('/\D/', '', $m[1]) ?: '0';
            $fraction = str_pad($m[2], 2, '0');
        } else {
            $whole = preg_replace('/\D/', '', $digits) ?: '0';
            $fraction = '00';
        }

        // Ten million euros is not a present. It is a parse of something that
        // was not a price — a product code in a price field.
        if (strlen(ltrim($whole, '0')) > 7) {
            return null;
        }

        return (int) $whole * 100 + (int) $fraction;
    }

    public static function availability(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower($value);

        return match (true) {
            str_contains($value, 'outofstock'), str_contains($value, 'out of stock'), str_contains($value, 'soldout'), str_contains($value, 'discontinued') => 'out_of_stock',
            str_contains($value, 'instock'), str_contains($value, 'in stock'), str_contains($value, 'limitedavailability') => 'in_stock',
            default => null,
        };
    }

    /**
     * The fields of the first `Product` in the page's JSON-LD.
     *
     * @return array<string, mixed>
     */
    private function fromJsonLd(DOMXPath $xpath): array
    {
        $products = [];

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $data = json_decode(trim($script->textContent), true);

            if (is_array($data)) {
                $this->collectProducts($data, $products);
            }
        }

        // The one with a name and an offer is the page's own product; the
        // others are usually "related products" further down.
        usort($products, fn (array $a, array $b) => (int) isset($b['offers']) <=> (int) isset($a['offers']));

        $product = $products[0] ?? null;

        if ($product === null) {
            return [];
        }

        $offer = $this->firstOffer($product['offers'] ?? null)
            // A ProductGroup (a shirt in five sizes) carries its offers on the
            // variants. Take the first variant's, and only its price: its
            // barcode belongs to that one size, not to the group.
            ?? $this->firstOffer($product['hasVariant'][0]['offers'] ?? null);

        $brand = $product['brand'] ?? null;

        return [
            'title' => $product['name'] ?? null,
            'brand' => is_array($brand) ? ($brand['name'] ?? null) : $brand,
            'description' => $product['description'] ?? null,
            'image' => $product['image'] ?? null,
            'price' => $offer['price'] ?? $offer['lowPrice'] ?? null,
            'currency' => $offer['priceCurrency'] ?? null,
            'availability' => $offer['availability'] ?? null,
            'gtin' => $product['gtin13'] ?? $product['gtin'] ?? $product['gtin12'] ?? $product['gtin14'] ?? $product['gtin8'] ?? $product['isbn'] ?? null,
            'mpn' => $product['mpn'] ?? null,
        ];
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<array<string, mixed>>  $out
     */
    private function collectProducts(array $node, array &$out, int $depth = 0): void
    {
        if ($depth > 5) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $child) {
                if (is_array($child)) {
                    $this->collectProducts($child, $out, $depth + 1);
                }
            }

            return;
        }

        $types = array_map('strval', (array) ($node['@type'] ?? []));

        if (array_intersect($types, ['Product', 'ProductGroup', 'IndividualProduct', 'ProductModel']) !== [] && isset($node['name'])) {
            $out[] = $node;
        }

        foreach (['@graph', 'mainEntity', 'itemReviewed'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                $this->collectProducts($node[$key], $out, $depth + 1);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function firstOffer(mixed $offers): ?array
    {
        if (! is_array($offers)) {
            return null;
        }

        if (array_is_list($offers)) {
            $offers = $offers[0] ?? null;

            return is_array($offers) ? $offers : null;
        }

        // An AggregateOffer nests the real ones one level down, and carries a
        // lowPrice of its own that is good enough when they are absent.
        if (isset($offers['offers']) && is_array($offers['offers']) && ! isset($offers['lowPrice'])) {
            return $this->firstOffer($offers['offers']);
        }

        return $offers;
    }

    /** @return array<string, string> */
    private function fromMeta(DOMXPath $xpath): array
    {
        $tags = [];

        foreach ($xpath->query('//meta[@property or @name]') ?: [] as $meta) {
            $key = strtolower($meta->getAttribute('property') ?: $meta->getAttribute('name'));
            $tags[$key] ??= $meta->getAttribute('content');
        }

        return array_filter([
            'title' => $tags['og:title'] ?? $tags['twitter:title'] ?? null,
            'image' => $tags['og:image:secure_url'] ?? $tags['og:image'] ?? $tags['twitter:image'] ?? null,
            'description' => $tags['og:description'] ?? $tags['description'] ?? null,
            'price' => $tags['product:price:amount'] ?? $tags['og:price:amount'] ?? null,
            'currency' => $tags['product:price:currency'] ?? $tags['og:price:currency'] ?? null,
            'brand' => $tags['product:brand'] ?? $tags['og:brand'] ?? null,
            'availability' => $tags['product:availability'] ?? $tags['og:availability'] ?? null,
        ], fn ($v) => is_string($v) && trim($v) !== '');
    }

    /** The first usable https image, made absolute against the page. */
    private function image(mixed $value, string $pageUrl): ?string
    {
        $candidates = match (true) {
            is_string($value) => [$value],
            is_array($value) && array_is_list($value) => $value,
            is_array($value) => [$value['url'] ?? $value['contentUrl'] ?? null],
            default => [],
        };

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate['url'] ?? $candidate['contentUrl'] ?? null;
            }

            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $absolute = SafeFetch::absolute(trim($candidate), $pageUrl);

            if (str_starts_with(strtolower($absolute), 'https://') && strlen($absolute) <= 1024) {
                return $absolute;
            }
        }

        return null;
    }

    private function currency(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z]{3}$/', trim($value)) ? strtoupper(trim($value)) : null;
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $text === '' ? null : $text;
    }
}
