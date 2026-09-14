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
     * @param  list<string>  $matchedInterests  interests it answered, strongest slot first
     * @param  list<array{kind: string, value: string}>  $matchedTastes  vibe, preference and values poles it sits at
     */
    public function __construct(
        public ProductGroup $group,
        public float $score,
        public array $breakdown,
        public array $matchedQueries = [],
        public ?string $primaryInterest = null,
        public array $matchedInterests = [],
        public array $matchedTastes = [],
    ) {}

    /**
     * What this present has in common with the brief, for the card.
     *
     * Not a sentence. The card used to carry the strongest signal as prose,
     * "matches cooking", and the owner cut the wording (2026-09-14): saying
     * *that* something fits adds nothing a shopper cannot see, and the words
     * around the fact crowd out the fact. The card lists what it fits with
     * instead, interests first and then the taste, and the reader draws the
     * conclusion.
     *
     * Capped, because a product answering six things is a wall of chips and
     * the first few are the strongest anyway.
     *
     * @return list<array{kind: string, value: string}>
     */
    public function fits(int $limit = 4): array
    {
        $fits = array_map(
            fn (string $interest) => ['kind' => 'interest', 'value' => $interest],
            $this->matchedInterests,
        );

        return array_slice([...$fits, ...$this->matchedTastes], 0, $limit);
    }

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
