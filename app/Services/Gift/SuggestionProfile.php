<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * How to rank, for whom.
 *
 * The engine answers two questions with one pipeline: "what should I buy them?"
 * and "what do I want?". Retrieval, diversification and explanation are
 * genuinely identical; the weights are not, and one difference in particular
 * would have been invisible if this class did not exist — see
 * {@see budgetFit()}.
 *
 * Deliberately shaped like a Mode Profile from `config/discovery.php` (named
 * weights, a λ, a presentation hint) so that folding these into the discovery
 * dial later is a data change rather than a rewrite.
 */
final readonly class SuggestionProfile
{
    public const FOR_SOMEONE = 'for_someone';

    public const FOR_MYSELF = 'for_myself';

    /** @param array<string, float> $weights */
    private function __construct(
        public string $key,
        public array $weights,
        public float $mmrLambda,
        public string $budgetShape,
    ) {}

    /** Buying for another person: today's Gift Whisperer, unchanged. */
    public static function forSomeone(): self
    {
        return self::named(self::FOR_SOMEONE);
    }

    /** Building your own list. */
    public static function forMyself(): self
    {
        return self::named(self::FOR_MYSELF);
    }

    public static function named(string $key): self
    {
        /** @var array<string, mixed> $config */
        $config = config('brandcoves.gift.profiles.'.$key)
            ?? config('brandcoves.gift.profiles.'.self::FOR_SOMEONE)
            ?? [];

        return new self(
            key: $key,
            weights: array_map(
                fn ($w) => (float) $w,
                (array) ($config['weights'] ?? config('brandcoves.gift.weights')),
            ),
            mmrLambda: (float) ($config['mmr_lambda'] ?? config('brandcoves.gift.mmr_lambda')),
            budgetShape: (string) ($config['budget_shape'] ?? 'sweet_spot'),
        );
    }

    public function weight(string $signal, float $fallback): float
    {
        return $this->weights[$signal] ?? $fallback;
    }

    public function isForMyself(): bool
    {
        return $this->key === self::FOR_MYSELF;
    }

    /**
     * How well a price fits the budget, 0 to 1.
     *
     * Two shapes, and the difference is the whole reason profiles exist.
     *
     * **`sweet_spot`** — peaks at 85% of the ceiling and falls away on both
     * sides, so the engine spends the budget without exceeding it. A €12 gift
     * against a €100 budget reads as thoughtless rather than thrifty. That is a
     * rule about how a *present* is received by another person.
     *
     * **`in_range`** — flat. Nobody thinks their own €12 wish is thoughtless,
     * and a wishlist that quietly buries everything affordable is worse than
     * useless: the cheap things are the ones people actually get bought. The
     * budget is a filter here, applied in retrieval, and this signal only
     * distinguishes "priced at all" from "not".
     */
    public function budgetFit(?int $priceCents, int $ceilingCents): float
    {
        if ($priceCents === null) {
            return 0.0;
        }

        if ($this->budgetShape === 'in_range') {
            return 1.0;
        }

        $sweetSpot = max(1, $ceilingCents) * (float) config('brandcoves.gift.budget_sweet_spot');

        // Symmetric falloff around 1.0, clamped. A distance of 1.0 in either
        // direction scores zero, which puts a €10 item against a €100 budget at
        // roughly 0.12 — present, but not competitive.
        return max(0.0, 1.0 - abs(1.0 - ($priceCents / $sweetSpot)));
    }
}
