<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;

/**
 * The canonical shape every connector normalises to.
 *
 * One of these is one OFFER — a single merchant selling a single thing in a
 * single market. Grouping them into physical products happens downstream.
 */
final readonly class Offer
{
    public function __construct(
        public Source $source,
        public string $externalId,
        public Market $market,
        public string $title,
        public string $affiliateUrl,
        /** Cents. Null when the feed gave us nothing parseable. */
        public ?int $price = null,
        public ?string $description = null,
        public ?string $brand = null,
        public ?string $merchantName = null,
        public ?string $merchantExternalId = null,
        /** The merchant's own product URL — the only reliable source of their domain. */
        public ?string $merchantDeepLink = null,
        public ?string $merchantCategory = null,
        public ?string $imageUrl = null,
        public ?string $ean = null,
        public ?int $referencePrice = null,
        public string $currency = 'EUR',
        public Availability $availability = Availability::Unknown,
        public ?float $commissionRate = null,
    ) {}

    /**
     * Whether this row is worth storing at all.
     *
     * A row without a usable affiliate URL cannot earn anything and cannot be
     * clicked; a row without a title cannot be searched or displayed. Both are
     * common in real feeds, so this is a filter rather than an error.
     */
    public function isValid(): bool
    {
        return trim($this->title) !== ''
            && trim($this->externalId) !== ''
            && $this->hasSafeAffiliateUrl();
    }

    /**
     * Affiliate URLs come from third-party feeds and are hostile input.
     *
     * Checked here, at the boundary, so a `javascript:` or `data:` URL never
     * reaches the database — HTML escaping downstream would happily preserve it
     * and the click-out redirector would emit it as a Location header.
     */
    public function hasSafeAffiliateUrl(): bool
    {
        $url = trim($this->affiliateUrl);
        if ($url === '') {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && strtolower($scheme) === 'https';
    }

    /**
     * The merchant's real domain.
     *
     * Never derived from affiliateUrl: that is a network tracking URL
     * (awin1.com/pclick.php), so it would yield the network's domain — and
     * therefore the network's favicon — for every merchant alike.
     */
    public function merchantDomain(): ?string
    {
        if ($this->merchantDeepLink === null || $this->merchantDeepLink === '') {
            return null;
        }

        $host = parse_url($this->merchantDeepLink, PHP_URL_HOST);
        if (! is_string($host)) {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
