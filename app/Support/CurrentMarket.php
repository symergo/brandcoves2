<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Market;
use App\Http\Middleware\SetMarket;

/**
 * The market for the current request, resolved once by {@see SetMarket}
 * and injectable anywhere.
 *
 * A dedicated object rather than a global helper so that anything depending on
 * the market says so in its constructor — and so tests can bind a different one
 * without touching global state.
 */
final readonly class CurrentMarket
{
    public function __construct(public Market $market) {}

    public function value(): string
    {
        return $this->market->value;
    }

    public function get(): Market
    {
        return $this->market;
    }

    /** Prefix a path with the current market: 'search' -> '/be-nl/search'. */
    public function url(string $path = ''): string
    {
        return '/'.$this->market->value.($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /**
     * The same page in another market, for hreflang alternates.
     *
     * Swaps only the leading market segment and leaves the rest of the path
     * intact, so /be-nl/guides/beste-koptelefoons maps to
     * /be-fr/guides/beste-koptelefoons. The slug will not be translated yet —
     * that is a Phase 6 concern once guides exist per market — but the page
     * resolves, which is what hreflang requires.
     */
    public static function swapMarketInPath(string $path, Market $target): string
    {
        $segments = explode('/', trim($path, '/'));

        if ($segments === [] || $segments[0] === '') {
            return '/'.$target->value;
        }

        // Only replace it if the first segment really is a market; otherwise
        // this is an unprefixed route and we would corrupt the path.
        if (Market::tryFrom($segments[0]) !== null) {
            $segments[0] = $target->value;
        } else {
            array_unshift($segments, $target->value);
        }

        return '/'.implode('/', $segments);
    }
}
