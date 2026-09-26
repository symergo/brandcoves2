<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use App\Models\ProductGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which products to show next in taste discovery.
 *
 * ## Rounds are chosen to learn fast
 *
 * A dozen rounds is all anybody gives a quiz, so every round has to teach
 * something. Three rules do most of the work:
 *
 * 1. **The two sides of a pair never share an interest.** A coffee grinder
 *    against a coffee cup teaches nothing about interests; a coffee grinder
 *    against a yoga mat teaches one thing clearly.
 * 2. **The first four rounds explore, the rest focus.** Exploring shows the
 *    interests seen least so far (so four pairs cover about eight interests).
 *    Focusing puts the current favourites against each other and against
 *    something new, at a similar price, so the choice is about the interest
 *    and not the price, and a favourite has to win twice to count
 *    (TasteProfiler's threshold).
 * 3. **Exploring pairs differ in price** (one 1.5 to 4 times the other), so
 *    what was picked also says something about the price band; focusing
 *    pairs sit within 1.6 times of each other for the reason above.
 *
 * Every fourth round is a single card, like or dislike. While focusing, that
 * card carries an interest that has been passed over, to confirm or clear it
 * before it lands on the avoid list, which needs two bad rounds.
 *
 * ## Random, within the market
 *
 * The pool is a random draw of presentable, giftable products in the market
 * that carry an interest tag (an editor's or the crowd's), so two sessions
 * never see the same deck and nothing favours a well-stocked shop. When a
 * market has too few tagged products the draw is topped up with untagged
 * ones: they still teach the price band, and a page that shows nothing is
 * worse.
 */
final class TasteDeck
{
    /** Rounds in a session. "About ten to twelve" is the owner's brief. */
    public const ROUNDS = 12;

    /** Rounds sent per request: the page asks for the next batch while two are still left. */
    public const BATCH = 4;

    /** Rounds before the deck starts focusing on favourites. */
    public const EXPLORE = 4;

    /** Every fourth round is one card, like or dislike. */
    public const SINGLE_EVERY = 4;

    /** Random draw per request. Plenty for four rounds with room to be choosy. */
    private const POOL = 160;

    private const EXPLORE_MIN_RATIO = 1.5;

    private const EXPLORE_MAX_RATIO = 4.0;

    private const FOCUS_MAX_RATIO = 1.6;

    public function __construct(private readonly TasteProfiler $profiler) {}

    /**
     * The next rounds, as products.
     *
     * @param  list<TasteChoice>  $choices  answered so far
     * @param  list<int>  $exclude  every product already shown or queued
     * @param  int  $from  the index of the first round to compose
     * @return list<list<ProductGroup>>
     */
    public function next(Market $market, array $choices, array $exclude, int $from, int $count = self::BATCH): array
    {
        $count = max(0, min($count, self::ROUNDS - $from));

        if ($count === 0) {
            return [];
        }

        $groups = $this->draw($market, $exclude);
        $byId = $groups->keyBy('id');

        $rounds = $this->compose(
            $groups->map(fn (ProductGroup $g) => TasteCard::fromGroup($g))->values()->all(),
            $choices,
            $from,
            $count,
        );

        return array_map(
            fn (array $round) => array_map(fn (TasteCard $card) => $byId[$card->id], $round),
            $rounds,
        );
    }

    /**
     * The pure half: rounds out of a pool, given what has been learned.
     *
     * The pool's order is the tie-breaker, and the pool arrives shuffled, so
     * the deck is random where the rules leave a choice and deterministic in a
     * test that passes a fixed pool.
     *
     * @param  list<TasteCard>  $pool
     * @param  list<TasteChoice>  $choices
     * @return list<list<TasteCard>>
     */
    public function compose(array $pool, array $choices, int $from, int $count): array
    {
        $profile = $this->profiler->profile($choices);

        $exposure = [];

        foreach ($choices as $choice) {
            foreach ($choice->cards as $card) {
                foreach ($card->interests() as $interest) {
                    $exposure[$interest] = ($exposure[$interest] ?? 0) + 1;
                }
            }
        }

        $scores = $profile->scores;
        arsort($scores);

        $leaders = array_slice(array_map('strval', array_keys(array_filter($scores, fn (float $s) => $s > 0))), 0, 3);

        // Passed over but not yet avoided: worth one more look on a single card.
        $doubts = array_values(array_diff(
            array_map('strval', array_keys(array_filter($scores, fn (float $s) => $s < 0))),
            $profile->avoid,
        ));

        $rounds = [];

        for ($i = 0; $i < $count && $pool !== []; $i++) {
            $index = $from + $i;
            $focus = $index >= self::EXPLORE && $leaders !== [];

            if (($index + 1) % self::SINGLE_EVERY === 0) {
                $card = ($focus ? $this->carrying($pool, $doubts, $exposure) : null)
                    ?? $this->freshest($pool, $exposure);

                $rounds[] = [$card];
                $pool = $this->without($pool, $card);
                $this->expose($exposure, $card);

                continue;
            }

            $leader = $focus ? $leaders[$i % count($leaders)] : null;

            $first = ($leader !== null ? $this->carrying($pool, [$leader], $exposure) : null)
                ?? $this->freshest($pool, $exposure);

            $pool = $this->without($pool, $first);
            $this->expose($exposure, $first);

            // Every other focusing round pits two favourites against each other;
            // the rest put a favourite against something not yet seen.
            $rivals = $focus && $i % 2 === 1 ? array_values(array_diff($leaders, [$leader])) : [];

            $second = $this->opponent($pool, $first, $focus, $rivals, $exposure);

            if ($second === null) {
                $rounds[] = [$first];

                continue;
            }

            $rounds[] = [$first, $second];
            $pool = $this->without($pool, $second);
            $this->expose($exposure, $second);
        }

        return $rounds;
    }

    /**
     * The best card to face `$first`, by a ladder of rules relaxed one at a
     * time, so a thin pool still yields a pair: no shared interest, the price
     * rule, a rival favourite when asked for; then without the rival; then
     * without the price rule; then anything.
     *
     * @param  list<TasteCard>  $pool
     * @param  list<string>  $rivals
     * @param  array<string, int>  $exposure
     */
    private function opponent(array $pool, TasteCard $first, bool $focus, array $rivals, array $exposure): ?TasteCard
    {
        $best = null;
        $bestKey = null;

        foreach ($pool as $position => $card) {
            $disjoint = ! $card->sharesAnInterestWith($first);
            $priced = $this->pricesFit($first->price, $card->price, $focus);
            $rival = $rivals !== [] && array_intersect($card->interests(), $rivals) !== [];

            $tier = match (true) {
                $disjoint && $priced && ($rivals === [] || $rival) => 0,
                $disjoint && $priced => 1,
                $disjoint => 2,
                default => 3,
            };

            $key = [$tier, $this->staleness($card, $exposure), $position];

            if ($bestKey === null || $key < $bestKey) {
                $best = $card;
                $bestKey = $key;
            }
        }

        return $best;
    }

    private function pricesFit(?int $a, ?int $b, bool $focus): bool
    {
        if ($a === null || $b === null || $a <= 0 || $b <= 0) {
            return false;
        }

        $ratio = max($a, $b) / min($a, $b);

        return $focus
            ? $ratio <= self::FOCUS_MAX_RATIO
            : $ratio >= self::EXPLORE_MIN_RATIO && $ratio <= self::EXPLORE_MAX_RATIO;
    }

    /**
     * The card whose interests have been seen least. Untagged cards come last:
     * they teach only the price.
     *
     * @param  list<TasteCard>  $pool
     * @param  array<string, int>  $exposure
     */
    private function freshest(array $pool, array $exposure): TasteCard
    {
        $best = $pool[0];
        $bestKey = null;

        foreach ($pool as $position => $card) {
            $key = [$this->staleness($card, $exposure), $position];

            if ($bestKey === null || $key < $bestKey) {
                $best = $card;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * The freshest card carrying one of these interests, or null.
     *
     * @param  list<TasteCard>  $pool
     * @param  list<string>  $interests
     * @param  array<string, int>  $exposure
     */
    private function carrying(array $pool, array $interests, array $exposure): ?TasteCard
    {
        if ($interests === []) {
            return null;
        }

        $matching = array_values(array_filter(
            $pool,
            fn (TasteCard $card) => array_intersect($card->interests(), $interests) !== [],
        ));

        return $matching === [] ? null : $this->freshest($matching, $exposure);
    }

    /** @param  array<string, int>  $exposure */
    private function staleness(TasteCard $card, array $exposure): int
    {
        $interests = $card->interests();

        if ($interests === []) {
            return PHP_INT_MAX;
        }

        return min(array_map(fn (string $i) => $exposure[$i] ?? 0, $interests));
    }

    /** @param  array<string, int>  $exposure */
    private function expose(array &$exposure, TasteCard $card): void
    {
        foreach ($card->interests() as $interest) {
            $exposure[$interest] = ($exposure[$interest] ?? 0) + 1;
        }
    }

    /**
     * @param  list<TasteCard>  $pool
     * @return list<TasteCard>
     */
    private function without(array $pool, TasteCard $card): array
    {
        return array_values(array_filter($pool, fn (TasteCard $c) => $c->id !== $card->id));
    }

    /**
     * A random draw of what may be shown.
     *
     * @param  list<int>  $exclude
     * @return Collection<int, ProductGroup>
     */
    private function draw(Market $market, array $exclude): Collection
    {
        $tagged = $this->base($market, $exclude)
            ->where(fn (Builder $q) => $q
                ->whereRaw('product_groups.gift_tags::text like ?', ['%"interest:%'])
                ->orWhereRaw('product_groups.crowd_tags::text like ?', ['%"interest:%']))
            ->inRandomOrder()
            ->limit(self::POOL)
            ->get();

        if ($tagged->count() >= self::BATCH * 4) {
            return $tagged;
        }

        $untagged = $this->base($market, [...$exclude, ...$tagged->pluck('id')->all()])
            ->inRandomOrder()
            ->limit(self::POOL - $tagged->count())
            ->get();

        return $tagged->concat($untagged)->values();
    }

    /**
     * @param  list<int>  $exclude
     * @return Builder<ProductGroup>
     */
    private function base(Market $market, array $exclude): Builder
    {
        $query = ProductGroup::query()
            ->forMarket($market)
            ->giftable()
            ->presentable()
            ->whereBetween('min_price', [(int) config('giftcoves.gift.min_price'), (int) config('giftcoves.gift.max_price')]);

        if ($exclude !== []) {
            $query->whereNotIn('id', $exclude);
        }

        return $query;
    }
}
