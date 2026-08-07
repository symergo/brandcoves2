<?php

declare(strict_types=1);

namespace App\Services\Discover;

use App\Models\ProductGroup;

/**
 * One product on its way through the pipeline.
 *
 * Mutable-by-copy: each stage returns a new Candidate rather than editing one,
 * so a retriever cannot silently overwrite a score another retriever set. The
 * signals accumulate; the ranker reads them.
 */
final readonly class Candidate
{
    /**
     * @param  array<string, float>  $signals  0..1 per signal, set by retrievers
     * @param  list<string>  $sources  which retrievers surfaced this
     */
    public function __construct(
        public ProductGroup $group,
        public array $signals = [],
        public array $sources = [],
        public float $score = 0.0,
        public ?string $reason = null,
    ) {}

    /** @param array<string, float> $signals */
    public function withSignals(array $signals, string $source): self
    {
        $merged = $this->signals;

        foreach ($signals as $name => $value) {
            // Max, not sum: two retrievers both finding this product means the
            // stronger evidence wins, not that the product is twice as
            // relevant. Summing lets a candidate win by being mediocre in
            // several ways at once.
            $merged[$name] = max($merged[$name] ?? 0.0, $value);
        }

        return new self(
            group: $this->group,
            signals: $merged,
            sources: array_values(array_unique([...$this->sources, $source])),
            score: $this->score,
            reason: $this->reason,
        );
    }

    public function scored(float $score, ?string $reason): self
    {
        return new self($this->group, $this->signals, $this->sources, $score, $reason);
    }

    public function signal(string $name, float $default = 0.0): float
    {
        return $this->signals[$name] ?? $default;
    }
}
