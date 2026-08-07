<?php

declare(strict_types=1);

namespace App\Services\Discover;

/**
 * A discovery mode, as data.
 *
 * The whole point of this class is that it is boring. A mode is a config
 * object — a retriever mix, five scoring numbers and a layout name — and adding
 * one must never mean touching the pipeline. If a future mode needs a code
 * change, either the profile schema is missing a field or the mode is not
 * really a mode.
 *
 * ## The axis
 *
 * Modes sit on one axis: how much intent the user has, from pinpoint ("I know
 * the exact item") to none ("surprise me"). `position` is where a mode sits on
 * it, 0..1, and it is what lets the switcher interpolate between two adjacent
 * modes rather than hard-swapping between nine screens.
 *
 * ## The scoring parameters
 *
 * The objective is `relevance^α · unexpectedness^β · novelty^γ · quality`,
 * then MMR at λ, then ε-greedy exploration. Multiplicative rather than a
 * weighted sum, deliberately: a candidate that fails on quality should not be
 * able to buy its way back with unexpectedness. That is the same reasoning as
 * the Serendipity Engine's rarity × worth-seeing, generalised.
 */
final readonly class ModeProfile
{
    /**
     * @param  array<string, float>  $retrievers  retriever key => weight
     * @param  list<string>  $requiredInput  which input fields the mode needs
     */
    public function __construct(
        public string $key,
        public string $intent,
        public float $position,
        public array $retrievers,
        public float $alpha,
        public float $beta,
        public float $gamma,
        public float $lambda,
        public float $epsilon,
        public string $layout,
        public array $requiredInput = [],
        public bool $enabled = true,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromArray(string $key, array $row): self
    {
        $scoring = (array) ($row['scoring'] ?? []);

        return new self(
            key: $key,
            intent: (string) ($row['intent'] ?? ''),
            position: (float) ($row['position'] ?? 0),
            retrievers: array_map('floatval', (array) ($row['retrievers'] ?? [])),
            alpha: (float) ($scoring['alpha'] ?? 1.0),
            beta: (float) ($scoring['beta'] ?? 0.0),
            gamma: (float) ($scoring['gamma'] ?? 0.0),
            lambda: (float) ($scoring['lambda'] ?? 0.2),
            epsilon: (float) ($scoring['epsilon'] ?? 0.0),
            layout: (string) ($row['layout'] ?? 'list'),
            requiredInput: array_values((array) ($row['required_input'] ?? [])),
            enabled: (bool) ($row['enabled'] ?? true),
        );
    }

    /**
     * Blend two profiles.
     *
     * This is the mode switcher. Dragging the dial from Search toward
     * Serendipity does not swap one profile for another at a threshold — it
     * interpolates α down and β/γ/ε up, and cross-fades the retriever weights,
     * so the same result surface visibly reorganises as the user drags. That is
     * what makes it read as one dial rather than nine screens.
     *
     * Layout comes from whichever end is nearer: a layout cannot be half of
     * two things.
     */
    public function blend(self $other, float $t): self
    {
        $t = max(0.0, min(1.0, $t));
        $lerp = fn (float $a, float $b): float => $a + ($b - $a) * $t;

        $keys = array_unique([...array_keys($this->retrievers), ...array_keys($other->retrievers)]);
        $retrievers = [];

        foreach ($keys as $key) {
            $weight = $lerp($this->retrievers[$key] ?? 0.0, $other->retrievers[$key] ?? 0.0);

            // Drop weights that have faded to nothing rather than running a
            // retriever for a rounding error.
            if ($weight > 0.01) {
                $retrievers[$key] = round($weight, 3);
            }
        }

        return new self(
            key: $t < 0.5 ? $this->key : $other->key,
            intent: $t < 0.5 ? $this->intent : $other->intent,
            position: $lerp($this->position, $other->position),
            retrievers: $retrievers,
            alpha: $lerp($this->alpha, $other->alpha),
            beta: $lerp($this->beta, $other->beta),
            gamma: $lerp($this->gamma, $other->gamma),
            lambda: $lerp($this->lambda, $other->lambda),
            epsilon: $lerp($this->epsilon, $other->epsilon),
            layout: $t < 0.5 ? $this->layout : $other->layout,
            requiredInput: $t < 0.5 ? $this->requiredInput : $other->requiredInput,
        );
    }

    /**
     * Raise or lower unexpectedness without changing mode.
     *
     * The user-facing surprise dial. Applied on top of the profile so the
     * stored preference survives a mode change, and clamped so it can nudge the
     * results rather than replace the mode's character.
     */
    public function withSurprise(float $dial): self
    {
        $dial = max(0.0, min(1.0, $dial));

        return new self(
            key: $this->key,
            intent: $this->intent,
            position: $this->position,
            retrievers: $this->retrievers,
            // Below 0.5 the dial damps unexpectedness, above it amplifies —
            // 0.5 is "leave the profile alone", which is what a slider at the
            // middle should mean.
            alpha: $this->alpha,
            beta: $this->beta * (0.5 + $dial),
            gamma: $this->gamma * (0.5 + $dial),
            lambda: $this->lambda,
            epsilon: $this->epsilon,
            layout: $this->layout,
            requiredInput: $this->requiredInput,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'intent' => $this->intent,
            'position' => $this->position,
            'retrievers' => $this->retrievers,
            'scoring' => [
                'alpha' => round($this->alpha, 3),
                'beta' => round($this->beta, 3),
                'gamma' => round($this->gamma, 3),
                'lambda' => round($this->lambda, 3),
                'epsilon' => round($this->epsilon, 3),
            ],
            'layout' => $this->layout,
        ];
    }
}
