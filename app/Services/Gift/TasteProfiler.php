<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Preference;

/**
 * Choices in, a taste out. No AI, no database: arithmetic over the tags of the
 * products that were chosen and passed over.
 *
 * ## The scoring, and why these numbers
 *
 * Every interest, vibe, taste pole and value on a card gets a running score:
 *
 * | What happened to the card | Score per tag |
 * |---|---|
 * | picked from a pair | +1 |
 * | liked on its own | +1 |
 * | passed over in a pair | -0.5 |
 * | disliked on its own | -1 |
 *
 * multiplied by how much the tag is trusted (1 for an editor's, 0.75 for the
 * crowd's; see TasteCard::values()).
 *
 * **Passing over is half a dislike.** Picking the coffee grinder over the
 * yoga mat says "rather this", not "never that". Somebody who loves both
 * still has to pick one, and scoring the loser at -1 would put half of their
 * real interests on the avoid list after a dozen rounds.
 *
 * **A tag both cards share is not counted either way.** It cannot be what the
 * choice was about. The deck avoids such pairs for interests, but vibe and
 * taste poles are not controlled, and a "beautiful" on both sides says
 * nothing.
 *
 * **An interest needs two good rounds** (net 1.5 or more, so two picks, or two
 * picks and a pass). One pick is often the nicer photo. When nothing reaches
 * that after a short session, the best one or two with at least one clean
 * pick are taken, so a person who stopped after four rounds still sees
 * something rather than nothing, and the page says it is a thin reading.
 *
 * **Avoid needs two separate bad rounds and no good one.** Avoid is a hard
 * filter in the engine: one wrong entry hides a whole shelf. So a single
 * dislike is not enough, and an interest that was ever picked is never
 * avoided, whatever else happened to it.
 *
 * **The price band** is the middle half of what was picked or liked (lower
 * to upper quartile), widened by a fifth each way and rounded to five euros,
 * from three prices up. Fewer than three is not a band, it is an anecdote.
 */
final class TasteProfiler
{
    public const PICKED = 1.0;

    public const LIKED = 1.0;

    public const PASSED_OVER = -0.5;

    public const DISLIKED = -1.0;

    /** Net score an interest needs to count as one they love. */
    public const INTEREST_THRESHOLD = 1.5;

    /** How many interests a profile names at most. More reads as "everything". */
    public const MAX_INTERESTS = 4;

    /** Bad rounds (with no good one) before an interest is avoided. */
    public const AVOID_ROUNDS = 2;

    public const MAX_AVOID = 3;

    /** Net score a vibe, taste pole or value needs. The same bar as an interest. */
    public const TASTE_THRESHOLD = 1.5;

    public const MIN_PRICES = 3;

    /** Widening of the quartile band, each way. */
    public const BAND_SLACK = 0.2;

    /** Rounded to this, in cents: "about €30 to €60" rather than "€27.40 to €61.15". */
    public const ROUND_TO = 500;

    public function __construct(
        private readonly float $crowdWeight = 0.75,
        private readonly int $floor = 500,
        private readonly int $ceiling = 50000,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            crowdWeight: (float) config('giftcoves.list_signals.weight', 0.75),
            floor: (int) config('giftcoves.gift.min_price', 500),
            ceiling: (int) config('giftcoves.gift.max_price', 50000),
        );
    }

    /** @param  list<TasteChoice>  $choices */
    public function profile(array $choices): TasteProfile
    {
        $interests = $this->tally($choices, GiftTags::INTEREST);

        return new TasteProfile(
            interests: $this->loved($interests),
            scores: array_map(fn (array $row) => $row['score'], $interests),
            avoid: $this->avoided($interests),
            budgetMin: $this->band($choices)[0],
            budgetMax: $this->band($choices)[1],
            vibe: $this->vibe($choices),
            preferences: $this->preferences($choices),
            values: $this->strongest($this->tally($choices, GiftTags::VALUES), TasteProfile::MAX_VALUES),
            answered: count(array_filter($choices, fn (TasteChoice $c) => ! $c->isSkipped())),
        );
    }

    /**
     * Several people's runs about the same person, as one profile.
     *
     * Every run's choices go into one tally, as if one person had played all
     * the rounds. That is deliberate, and the rules above already say why it
     * works: an interest needs two good rounds, so two friends who each
     * picked cooking once agree on it where neither alone would; avoid needs
     * two bad rounds and no good one, so one friend's dislike is not enough
     * and one friend's pick overrules everybody's; and the price band is the
     * middle half of everything anyone picked, so one generous friend widens
     * it rather than moving it. No friend counts for more than the rounds they
     * played, and a run is capped at the rounds a page can send
     * (TasteDeck::ROUNDS * 2).
     *
     * @param  list<list<TasteChoice>>  $runs
     */
    public function combined(array $runs): TasteProfile
    {
        return $this->profile(array_merge([], ...array_values($runs)));
    }

    /**
     * Net score, good rounds and bad rounds per value of one vocabulary.
     *
     * @param  list<TasteChoice>  $choices
     * @return array<string, array{score: float, good: int, bad: int}>
     */
    private function tally(array $choices, string $vocabulary): array
    {
        $rows = [];

        $add = function (string $value, float $delta) use (&$rows): void {
            $rows[$value] ??= ['score' => 0.0, 'good' => 0, 'bad' => 0];
            $rows[$value]['score'] += $delta;
            $rows[$value][$delta > 0 ? 'good' : 'bad']++;
        };

        foreach ($choices as $choice) {
            if ($choice->isSkipped()) {
                continue;
            }

            $chosen = $choice->chosen();
            $lost = $choice->passedOver();
            $disliked = $choice->disliked();

            $won = $chosen?->values($vocabulary, $this->crowdWeight) ?? [];
            $passed = $lost?->values($vocabulary, $this->crowdWeight) ?? [];

            // Shared by both sides of a pair: not what the choice was about.
            $shared = array_intersect_key($won, $passed);

            foreach (array_diff_key($won, $shared) as $value => $weight) {
                $add($value, ($choice->isPair() ? self::PICKED : self::LIKED) * $weight);
            }

            foreach (array_diff_key($passed, $shared) as $value => $weight) {
                $add($value, self::PASSED_OVER * $weight);
            }

            foreach ($disliked?->values($vocabulary, $this->crowdWeight) ?? [] as $value => $weight) {
                $add($value, self::DISLIKED * $weight);
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, array{score: float, good: int, bad: int}>  $rows
     * @return list<string>
     */
    private function loved(array $rows): array
    {
        $ranked = $this->ranked($rows);

        $strong = array_values(array_filter(
            $ranked,
            fn (string $value) => $rows[$value]['score'] >= self::INTEREST_THRESHOLD,
        ));

        if ($strong !== []) {
            return array_slice($strong, 0, self::MAX_INTERESTS);
        }

        // A short session: the best one or two with at least a clean pick.
        return array_slice(array_values(array_filter(
            $ranked,
            fn (string $value) => $rows[$value]['score'] >= self::PICKED * $this->crowdWeight && $rows[$value]['good'] > 0,
        )), 0, 2);
    }

    /**
     * @param  array<string, array{score: float, good: int, bad: int}>  $rows
     * @return list<string>
     */
    private function avoided(array $rows): array
    {
        $bad = array_filter(
            $rows,
            fn (array $row) => $row['good'] === 0 && $row['bad'] >= self::AVOID_ROUNDS,
        );

        uasort($bad, fn (array $a, array $b) => $a['score'] <=> $b['score']);

        return array_slice(array_map('strval', array_keys($bad)), 0, self::MAX_AVOID);
    }

    /**
     * Highest score first; more good rounds, then the name, break a tie so the
     * same choices always give the same profile.
     *
     * @param  array<string, array{score: float, good: int, bad: int}>  $rows
     * @return list<string>
     */
    private function ranked(array $rows): array
    {
        $keys = array_map('strval', array_keys($rows));

        usort($keys, fn (string $a, string $b) => [$rows[$b]['score'], $rows[$b]['good'], $a]
            <=> [$rows[$a]['score'], $rows[$a]['good'], $b]);

        return $keys;
    }

    /**
     * @param  array<string, array{score: float, good: int, bad: int}>  $rows
     * @return list<string>
     */
    private function strongest(array $rows, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->ranked($rows),
            fn (string $value) => $rows[$value]['score'] >= self::TASTE_THRESHOLD,
        )), 0, $limit);
    }

    /**
     * One vibe, and only when it clearly leads: two vibes level is no answer.
     *
     * @param  list<TasteChoice>  $choices
     */
    private function vibe(array $choices): ?string
    {
        $rows = $this->tally($choices, GiftTags::VIBE);
        $ranked = $this->strongest($rows, 2);

        if ($ranked === []) {
            return null;
        }

        if (isset($ranked[1]) && $rows[$ranked[1]]['score'] >= $rows[$ranked[0]]['score']) {
            return null;
        }

        return $ranked[0];
    }

    /**
     * Up to three poles, never both ends of one axis: where both ends scored,
     * only the stronger counts, and only if it is ahead.
     *
     * @param  list<TasteChoice>  $choices
     * @return list<string>
     */
    private function preferences(array $choices): array
    {
        $rows = $this->tally($choices, GiftTags::PREFERENCE);
        $chosen = [];
        $axes = [];

        foreach ($this->strongest($rows, count($rows)) as $pole) {
            $preference = Preference::tryFrom($pole);

            if ($preference === null || in_array($preference->axis(), $axes, true)) {
                continue;
            }

            $opposite = $rows[$preference->opposite()->value]['score'] ?? 0.0;

            if ($opposite >= $rows[$pole]['score']) {
                continue;
            }

            $chosen[] = $pole;
            $axes[] = $preference->axis();
        }

        return array_slice($chosen, 0, TasteProfile::MAX_PREFERENCES);
    }

    /**
     * @param  list<TasteChoice>  $choices
     * @return array{0: int|null, 1: int|null}
     */
    private function band(array $choices): array
    {
        $prices = [];

        foreach ($choices as $choice) {
            $price = $choice->chosen()?->price;

            if ($price !== null && $price > 0) {
                $prices[] = $price;
            }
        }

        if (count($prices) < self::MIN_PRICES) {
            return [null, null];
        }

        sort($prices);

        $low = $this->quartile($prices, 0.25) * (1 - self::BAND_SLACK);
        $high = $this->quartile($prices, 0.75) * (1 + self::BAND_SLACK);

        $min = max($this->floor, (int) (floor($low / self::ROUND_TO) * self::ROUND_TO));
        $max = min($this->ceiling, (int) (ceil($high / self::ROUND_TO) * self::ROUND_TO));

        if ($max <= $min) {
            $max = min($this->ceiling, $min + self::ROUND_TO);
        }

        return [$min, $max];
    }

    /**
     * Linear interpolation between the closest ranks.
     *
     * @param  list<int>  $sorted
     */
    private function quartile(array $sorted, float $q): float
    {
        $position = (count($sorted) - 1) * $q;
        $below = (int) floor($position);
        $above = (int) ceil($position);

        return $sorted[$below] + ($sorted[$above] - $sorted[$below]) * ($position - $below);
    }
}
