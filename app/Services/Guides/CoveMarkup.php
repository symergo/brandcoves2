<?php

declare(strict_types=1);

namespace App\Services\Guides;

use App\Enums\Market;
use App\Support\CurrentMarket;

/**
 * Turns the link tokens in a Cove's prose into real internal links.
 *
 * ## Why the model never writes a URL
 *
 * Asked for links, a language model produces confident, well-formed, entirely
 * fictional ones — to brands we do not carry, products that do not exist and
 * paths that were never routed. Every one of them is a 404 in the middle of an
 * article, and at the scale this generates content that is a self-inflicted
 * crawl problem.
 *
 * So the contract is inverted. The builder hands the model an **allowlist** of
 * things it may link to, and the model writes tokens:
 *
 *     [[brand:Sony]]              → /{market}/search?brand=Sony
 *     [[search:draadloze koptelefoon]]
 *                                 → /{market}/search?q=draadloze+koptelefoon
 *     [[product:1234|Sony XM5]]   → /{market}/p/1234/slug
 *
 * Anything outside the allowlist is **stripped back to its label**, not
 * rendered as a link and not left as a visible token. A hallucinated brand
 * therefore degrades to plain text — the sentence still reads, and no reader or
 * crawler is sent anywhere that does not exist.
 *
 * That is the whole safety property: the model chooses *emphasis*, we choose
 * *destinations*.
 */
class CoveMarkup
{
    /** `[[kind:value]]` or `[[kind:value|label]]` */
    private const TOKEN = '/\[\[(brand|search|product):([^\]|]{1,120})(?:\|([^\]]{1,160}))?\]\]/u';

    /**
     * @param  array{brands: list<string>, searches: list<string>, products: array<int, array{slug: string, title: string}>}  $allowed
     * @return array{html: string, links: int, rejected: list<string>}
     */
    public function render(string $text, Market $market, array $allowed): array
    {
        $base = '/'.$market->value;
        $links = 0;
        $rejected = [];

        // Escape first, resolve second. The prose is model output and is
        // rendered as HTML, so anything that arrives already looking like
        // markup must stop being markup before we add our own.
        $escaped = e($text);

        $html = preg_replace_callback(
            self::TOKEN,
            function (array $m) use ($allowed, $base, &$links, &$rejected): string {
                $kind = $m[1];
                // The token survived escaping, so its contents are escaped too.
                $value = html_entity_decode($m[2], ENT_QUOTES);
                $label = isset($m[3]) ? html_entity_decode($m[3], ENT_QUOTES) : $value;

                $href = match ($kind) {
                    'brand' => $this->brand($value, $allowed['brands'] ?? [], $base),
                    'search' => $this->search($value, $allowed['searches'] ?? [], $base),
                    'product' => $this->product($value, $allowed['products'] ?? [], $base),
                    default => null,
                };

                if ($href === null) {
                    $rejected[] = "{$kind}:{$value}";

                    // Plain text, not a broken link and not a visible token.
                    return e($label);
                }

                $links++;

                return sprintf('<a href="%s">%s</a>', e($href), e($label));
            },
            $escaped,
        ) ?? $escaped;

        return ['html' => $html, 'links' => $links, 'rejected' => $rejected];
    }

    /**
     * Paragraphs, with tokens resolved.
     *
     * @param  array{brands: list<string>, searches: list<string>, products: array<int, array{slug: string, title: string}>}  $allowed
     * @return array{html: list<string>, links: int, rejected: list<string>}
     */
    public function paragraphs(string $text, Market $market, array $allowed): array
    {
        $out = [];
        $links = 0;
        $rejected = [];

        foreach (preg_split('/\R{2,}/u', trim($text)) ?: [] as $paragraph) {
            if (trim($paragraph) === '') {
                continue;
            }

            $result = $this->render($paragraph, $market, $allowed);
            $out[] = $result['html'];
            $links += $result['links'];
            $rejected = [...$rejected, ...$result['rejected']];
        }

        return ['html' => $out, 'links' => $links, 'rejected' => $rejected];
    }

    /** @param list<string> $brands */
    private function brand(string $value, array $brands, string $base): ?string
    {
        // Case-insensitive, because the model will not reproduce a feed's
        // capitalisation and "sony" meaning Sony is not a hallucination.
        foreach ($brands as $brand) {
            if (mb_strtolower($brand) === mb_strtolower(trim($value))) {
                return $base.'/search?'.http_build_query(['brand' => [$brand]]);
            }
        }

        return null;
    }

    /** @param list<string> $searches */
    private function search(string $value, array $searches, string $base): ?string
    {
        $needle = mb_strtolower(trim($value));

        foreach ($searches as $search) {
            if (mb_strtolower($search) === $needle) {
                return $base.'/search?'.http_build_query(['q' => $search]);
            }
        }

        return null;
    }

    /** @param array<int, array{slug: string, title: string}> $products */
    private function product(string $value, array $products, string $base): ?string
    {
        $id = (int) trim($value);

        return isset($products[$id])
            ? $base.'/p/'.$id.'/'.$products[$id]['slug']
            : null;
    }

    /**
     * The instruction block handed to the model.
     *
     * Kept next to the parser on purpose: a prompt that describes a syntax the
     * renderer does not implement is the most common way this kind of feature
     * rots, and the two drifting apart is silent — the tokens simply stop
     * becoming links.
     *
     * @param  array{brands: list<string>, searches: list<string>, products: array<int, array{slug: string, title: string}>}  $allowed
     */
    public function promptContract(array $allowed): string
    {
        $products = [];

        foreach (array_slice($allowed['products'] ?? [], 0, 20, true) as $id => $product) {
            $products[] = "{$id} = {$product['title']}";
        }

        return implode("\n", array_filter([
            'Link by writing tokens. Never write a URL, a markdown link or an HTML tag.',
            '  [[brand:NAME]]        [[search:PHRASE]]        [[product:ID|label]]',
            '',
            'You may ONLY use these. Anything else is deleted:',
            'Brands: '.implode(', ', array_slice($allowed['brands'] ?? [], 0, 40)),
            'Searches: '.implode(', ', array_slice($allowed['searches'] ?? [], 0, 40)),
            $products === [] ? null : 'Products: '.implode(' | ', $products),
        ]));
    }

    /** Convenience for a controller that already has the current market. */
    public function forCurrent(string $text, CurrentMarket $current, array $allowed): array
    {
        return $this->paragraphs($text, $current->get(), $allowed);
    }
}
