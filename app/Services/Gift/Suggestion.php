<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Models\ProductGroup;

/**
 * One suggestion, with the arithmetic that produced it.
 *
 * The breakdown is kept rather than discarded because "why did it pick this"
 * is the question everyone asks first — the shopper in the UI, and whoever is
 * tuning the weights six months from now. A recommender you cannot interrogate
 * is one you cannot fix.
 */
final readonly class Suggestion
{
    /**
     * @param  array<string, float>  $breakdown  signal name => points contributed
     * @param  list<string>  $matchedQueries  angle queries this product answered
     */
    public function __construct(
        public ProductGroup $group,
        public float $score,
        public array $breakdown,
        public array $matchedQueries = [],
        public ?string $primaryInterest = null,
    ) {}

    /**
     * The single strongest reason, for the card's one-line explanation.
     *
     * One reason, not a list: three reasons read as a machine justifying
     * itself, and the strongest one is almost always the true one.
     */
    public function topSignal(): string
    {
        $breakdown = $this->breakdown;
        arsort($breakdown);

        return (string) array_key_first($breakdown);
    }
}
