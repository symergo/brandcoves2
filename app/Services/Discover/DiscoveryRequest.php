<?php

declare(strict_types=1);

namespace App\Services\Discover;

use App\Enums\Market;

/**
 * What the user gave us, whatever mode they are in.
 *
 * One shape for every mode. A retriever reads the fields it cares about and
 * ignores the rest — the keyword retriever wants `query`, the slots retriever
 * wants `goal` and `budget`, and neither has to know the other exists.
 *
 * `modality` records how the query was produced (typed, spoken, photographed)
 * but never changes the mode. That is the point of it being an overlay: voice
 * is speech-to-text feeding the same `query` field, and an image feeds a vector
 * into the image retriever. Neither is a different way of *discovering*.
 */
final readonly class DiscoveryRequest
{
    /**
     * @param  array<string, mixed>  $answers  guided Q&A, for the advisor modes
     * @param  list<int>  $seedGroupIds  items the user pointed at (compare, more-like-this)
     * @param  list<int>  $excludeGroupIds  already shown this session
     */
    public function __construct(
        public Market $market,
        public ?string $query = null,
        public array $answers = [],
        public array $seedGroupIds = [],
        public ?string $goal = null,
        public ?int $budgetMin = null,
        public ?int $budgetMax = null,
        public array $excludeGroupIds = [],
        public int $limit = 24,
        public string $modality = 'text',
        public bool $social = false,
        public ?string $imageVector = null,
    ) {}

    public function hasQuery(): bool
    {
        return $this->query !== null && trim($this->query) !== '';
    }
}
