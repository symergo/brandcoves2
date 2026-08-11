<?php

declare(strict_types=1);

namespace App\Services\Discovery;

/**
 * One product's movement on one chart between two snapshots.
 *
 * `previousRank` null means it was not on the chart at all last time — a new
 * entry, which is a different event from "climbed a long way" and is kept
 * distinguishable rather than being folded into a very large delta.
 */
final readonly class TrendMove
{
    public function __construct(
        public string $externalId,
        public ?int $groupId,
        public ?string $title,
        public string $categoryExternalId,
        public ?string $categoryName,
        public int $rank,
        public ?int $previousRank,
    ) {}

    public function isNewEntry(): bool
    {
        return $this->previousRank === null;
    }

    /** Positive is up the chart. Null for a new entry — it came from nowhere. */
    public function delta(): ?int
    {
        return $this->previousRank === null ? null : $this->previousRank - $this->rank;
    }

    /**
     * How big a move this is relative to where it started, 0..1.
     *
     * Relative rather than absolute because ten places at the top of a chart is
     * a different event from ten places at the bottom.
     */
    public function magnitude(): float
    {
        if ($this->previousRank === null) {
            return 1.0;
        }

        $delta = $this->previousRank - $this->rank;

        return min(1.0, abs($delta) / max(1, $this->previousRank));
    }
}
