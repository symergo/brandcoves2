<?php

declare(strict_types=1);

namespace App\Services\PageReading;

use App\Enums\Source;
use App\Models\ProductGroup;
use App\Services\Connectors\Offer;

/**
 * Where a pasted link led.
 *
 * - `group`: a product we hold. The best answer: offers, prices, comparison.
 * - `offer`: a connector found it and we may keep it, but it joined no group.
 * - `known`: a source we must not read the page of (Amazon, bol, eBay) and
 *   could not place. The item stays as the person typed it.
 * - `unknown`: nothing recognised the link, so reading the page is allowed.
 */
final readonly class LinkResolution
{
    private function __construct(
        public string $kind,
        public ?Source $source = null,
        public ?ProductGroup $group = null,
        public ?Offer $offer = null,
    ) {}

    public static function group(ProductGroup $group, ?Source $source = null): self
    {
        return new self('group', $source, group: $group);
    }

    public static function offer(Offer $offer): self
    {
        return new self('offer', $offer->source, offer: $offer);
    }

    public static function known(Source $source): self
    {
        return new self('known', $source);
    }

    public static function unknown(): self
    {
        return new self('unknown');
    }

    public function mayReadPage(): bool
    {
        return $this->kind === 'unknown';
    }
}
