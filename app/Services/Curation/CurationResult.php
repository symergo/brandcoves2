<?php

declare(strict_types=1);

namespace App\Services\Curation;

use App\Enums\Source;

/**
 * One addable thing on the curation screen.
 *
 * Flattens the two halves of a search — the catalogue rows and the live offers
 * that may never become catalogue rows — into a single list a person can scan
 * and click. The distinction still exists underneath, because it decides what
 * gets stored, but it is not a distinction a curator should have to hold in
 * their head while choosing products.
 */
final readonly class CurationResult
{
    /**
     * @param  list<Source>  $sources  Which merchants' feeds this group was assembled from.
     *                                 Shown as a badge so a curator can see that the thing
     *                                 they just found arrived live rather than from the index.
     */
    public function __construct(
        public string $key,
        public ?int $groupId,
        public ?Source $liveSource,
        public ?string $externalId,
        public string $title,
        public ?string $brand,
        public ?string $imageUrl,
        public ?int $price,
        public int $merchantCount,
        public bool $inStock,
        public array $sources = [],
        public bool $alreadyAdded = false,
        /** "already on 12 Sep", "ran 3 Aug" — advisory, never a filter. */
        public ?string $conflict = null,
    ) {}

    /**
     * A catalogue product, addable by id.
     *
     * @param  list<Source>  $sources
     */
    public static function group(
        int $groupId,
        string $title,
        ?string $brand,
        ?string $imageUrl,
        ?int $price,
        int $merchantCount,
        bool $inStock,
        array $sources,
        bool $alreadyAdded,
        ?string $conflict = null,
    ): self {
        return new self(
            key: 'group:'.$groupId,
            groupId: $groupId,
            liveSource: null,
            externalId: null,
            title: $title,
            brand: $brand,
            imageUrl: $imageUrl,
            price: $price,
            merchantCount: $merchantCount,
            inStock: $inStock,
            sources: $sources,
            alreadyAdded: $alreadyAdded,
            conflict: $conflict,
        );
    }

    /**
     * An offer from a source whose catalogue may not be mirrored.
     *
     * Stored as a decision — the source and its id — and re-fetched at render.
     * Nothing here is written to `product_groups`, which is why it cannot be
     * addressed by a group id.
     */
    public static function live(Source $source, string $externalId, string $title, ?string $brand, ?string $imageUrl, ?int $price, bool $alreadyAdded): self
    {
        return new self(
            key: $source->value.':'.$externalId,
            groupId: null,
            liveSource: $source,
            externalId: $externalId,
            title: $title,
            brand: $brand,
            imageUrl: $imageUrl,
            price: $price,
            merchantCount: 1,
            inStock: true,
            sources: [$source],
            alreadyAdded: $alreadyAdded,
        );
    }

    /** @return array{0: string, 1: string}|null */
    public function parsedKey(): ?array
    {
        $parts = explode(':', $this->key, 2);

        return count($parts) === 2 ? [$parts[0], $parts[1]] : null;
    }
}
