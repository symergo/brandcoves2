<?php

declare(strict_types=1);

namespace App\Services\Connectors;

/**
 * A category a source will chart separately.
 *
 * bol does not publish a list of category ids anywhere. The only way to learn
 * one is to ask for a chart and read the relevant categories off the response,
 * which is why this rides back with the chart rather than coming from its own
 * endpoint: one request yields both this run's data and the next run's frontier.
 */
final readonly class ChartCategory
{
    public function __construct(
        public string $externalId,
        public string $name,
        /** null at the top level. */
        public ?string $parentExternalId = null,
        /** How many products the source claims are in it, when it says. */
        public ?int $productCount = null,
    ) {}

    public function isValid(): bool
    {
        return trim($this->externalId) !== '' && trim($this->name) !== '';
    }
}
