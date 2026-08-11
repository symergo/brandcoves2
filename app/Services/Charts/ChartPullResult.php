<?php

declare(strict_types=1);

namespace App\Services\Charts;

/** What one chart pull did. Counted rather than logged inline, so the job can total a run. */
final readonly class ChartPullResult
{
    public function __construct(
        public int $entries = 0,
        public int $productsWritten = 0,
        public int $productsSkipped = 0,
        public int $categoriesDiscovered = 0,
        /**
         * Whether the crawl stopped short of its work-list.
         *
         * Not a failure — the usual cause is the source telling us to back off.
         * It is the difference between "finished, start clean tomorrow" and
         * "paused, resume here", and the two must not be confused: a paused run
         * recorded as finished throws away its cursor and re-spends the requests
         * that caused the pause.
         */
        public bool $interrupted = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->entries === 0;
    }

    public function plus(self $other): self
    {
        return new self(
            entries: $this->entries + $other->entries,
            productsWritten: $this->productsWritten + $other->productsWritten,
            productsSkipped: $this->productsSkipped + $other->productsSkipped,
            categoriesDiscovered: $this->categoriesDiscovered + $other->categoriesDiscovered,
            interrupted: $this->interrupted || $other->interrupted,
        );
    }

    public function paused(): self
    {
        return new self(
            entries: $this->entries,
            productsWritten: $this->productsWritten,
            productsSkipped: $this->productsSkipped,
            categoriesDiscovered: $this->categoriesDiscovered,
            interrupted: true,
        );
    }
}
