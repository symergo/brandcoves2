<?php

declare(strict_types=1);

namespace App\Services\PageReading;

/**
 * The shop's own product id in one of its product links (2026-11-22).
 *
 * A pasted shop link used to be matched on its host and path against the stored
 * deep links (`products.merchant_deep_link`), with a 1.3 GB index of whole URLs to
 * make that fast. That index had not been used once in four weeks, and a path is a
 * fragile key: the same product has another path in another language
 * (`/nl/product/970016/…` against `/fr/produit/970016/…` at Coolblue) and after a
 * renamed slug. Every large Awin shop puts its own id in the path, so the id is
 * what is compared now, through a small index on the same function in the database:
 * `bc_shop_product_id()`, migration 2026_11_22_000200. Query parameters and
 * fragments never matter.
 *
 * **The two must agree.** The patterns here and in that SQL function are the same
 * list; tests/Feature/ShopProductIdTest.php runs every example through both. A
 * change here is a new migration replacing the function and its index.
 *
 * Patterns measured against a 1% sample of production's stored links, 2026-11-22:
 * Coolblue, DreamLand, Vanden Borre and Action 100%, Large 98%, Krefel and FNAC
 * ~100% once letters (`B8009341`) and FNAC's marketplace ids (`mp11207345`) were
 * allowed. Vanden Borre has no number in its links, so its last path segment, the
 * product's slug, stands in. Ids are compared lower case.
 *
 * bol and eBay are not here: LinkRouter already reads their ids and finds them by
 * `external_id`, which their connectors store as the shop's own id.
 */
final class ShopProductId
{
    /**
     * Host (itself or any subdomain of it) => pattern whose first group is the id.
     *
     * @var array<string, string>
     */
    public const PATTERNS = [
        'coolblue.be' => '#/(?:product|produit)/([0-9]+)#',
        'coolblue.nl' => '#/(?:product|produit)/([0-9]+)#',
        'fnac.be' => '#/((?:a|mp)[0-9]{4,})(?:[/?\#]|$)#',
        'shop.action.com' => '#/p/([0-9]{8,14})(?:[/?\#]|$)#',
        'dreamland.be' => '#/([0-9]{6,}(?:_[0-9]+)?)/?(?:[?\#]|$)#',
        'large.be' => '#/([0-9A-Za-z]+)\.html#',
        'vandenborre.be' => '#/([^/?\#]+)/?(?:[?\#]|$)#',
        'krefel.be' => '#/p/([0-9A-Za-z]+)(?:[-/?\#]|$)#',
    ];

    /** The id in this link, lower case, or null when the shop is not listed or the link carries none. */
    public static function fromUrl(string $url): ?string
    {
        $host = LinkRouter::host($url);

        if ($host === null || ($pattern = self::patternFor($host)) === null) {
            return null;
        }

        // The path onwards, as the SQL function sees it: everything after the host.
        $rest = (string) preg_replace('#^[a-z][a-z0-9+.-]*://[^/?\#]+#i', '', trim($url));

        return preg_match($pattern, $rest, $m) === 1 ? mb_strtolower($m[1]) : null;
    }

    /** Whether this shop's links are matched by id; if so, a link without one finds nothing. */
    public static function knows(string $host): bool
    {
        return self::patternFor($host) !== null;
    }

    private static function patternFor(string $host): ?string
    {
        foreach (self::PATTERNS as $suffix => $pattern) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return $pattern;
            }
        }

        return null;
    }
}
