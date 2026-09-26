<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * A product that might follow on from something already given: what the
 * scorer needs to know about it, and nothing it would have to look up.
 */
final readonly class NextStepCandidate
{
    public function __construct(
        public int $groupId,
        public string $title,
        public ?string $brand = null,
        public ?string $category = null,
        /** Cents (invariant 7). */
        public ?int $price = null,
    ) {}
}
