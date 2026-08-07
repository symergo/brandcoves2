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
}
