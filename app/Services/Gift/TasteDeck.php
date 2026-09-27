<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Interest;
use App\Enums\Market;
use App\Models\ProductGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

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
 * worse. A tenth of the pool is kept for proven gifts, products enough
 * different people keep on their lists (see PROVEN), once there are any.
 *
 * Since 2026-09-27 each request draws from a per-market pool cached for ten
 * minutes (pool()) instead of sorting the catalogue at random twice, and only
 * the products actually shown are loaded. The shares above are unchanged.
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

    /**
     * Proven gifts in each draw: products at least five different people keep
     * on a list for somebody (docs/features/crowd-picks.md). A tenth of the
     * pool, so they turn up a little more often than chance, and never so
     * many that the deck becomes the same few dozen popular things. They
     * count against the tagged half, and they pass the same filters.
     */
    private const PROVEN = 16;

    /** The proven gifts that share is drawn from at random, the most kept first. */
    private const PROVEN_CANDIDATES = 200;

    /**
     * How long a market's pool is kept (see pool()). Ten minutes: the same
     * window as the search caches, and long enough that the random sorts run
     * a few times an hour instead of on every card batch.
     */
    private const POOL_TTL = 600;

    /**
     * Tagged products in the pool. Above the ~700 per market production
     * holds (2026-09-26), so today the pool carries all of them.
     */
    private const POOL_TAGGED = 1500;

    /**
     * The random sample of everything else. About half survive
     * worthChoosing() (measured 2026-09-26), which leaves over a thousand to
     * draw a request's ~80 from.
     */
    private const POOL_OTHER = 2500;

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

        $rounds = $this->compose($this->draw($market, $exclude), $choices, $from, $count);

        /*
         * Only now are products loaded, and only the ones shown, with the
         * columns a card prints. Presentable again because the pool is up to
         * POOL_TTL old: a product that went out of stock since is dropped with
         * its round rather than shown, which the page absorbs (it asks for
         * more by index).
         */
        $ids = array_merge([], ...array_map(fn (array $round) => array_map(fn (TasteCard $c) => $c->id, $round), $rounds));

        $byId = $ids === [] ? collect() : ProductGroup::query()
            ->presentable()
            ->whereIn('id', $ids)
            ->get(['id', 'market', 'title', 'display_title', 'brand', 'image_url', 'min_price'])
            ->keyBy('id');

        $shown = [];

        foreach ($rounds as $round) {
            $groups = array_map(fn (TasteCard $card) => $byId->get($card->id), $round);

            if (! in_array(null, $groups, true)) {
                $shown[] = $groups;
            }
        }

        return $shown;
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
            // Among equally fresh cards, the one touching more interests: a
            // gaming headset teaches about gaming, music and tech in one round,
            // and twelve rounds cannot show forty interests one at a time.
            $key = [$this->staleness($card, $exposure), -min(3, count($card->interests())), $position];

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
     * A random draw of what may be shown, as cards, shuffled.
     *
     * Sampled in PHP from the market's cached pool (see pool()), where it used
     * to be two `ORDER BY random()` reads of every giftable product per
     * request, one of them with a `gift_tags::text like` no index can serve.
     * The shares are the same as they were: the proven gifts, then tagged
     * products up to half the draw, then the rest.
     *
     * @param  list<int>  $exclude
     * @return list<TasteCard>
     */
    private function draw(Market $market, array $exclude): array
    {
        $proven = $this->proven($market, $exclude);
        $skip = array_flip([...$exclude, ...array_map(fn (TasteCard $c) => $c->id, $proven)]);

        $pool = $this->pool($market);

        // At most half the pool. Tagged products are the surest evidence,
        // but on production they are some 700 per market, heavy on a few
        // interests (fitness, wellness), and a pool of only those showed
        // one music product in twelve rounds (simulated 2026-09-26).
        $tagged = $this->sample($pool['tagged'], $skip, max(0, intdiv(self::POOL, 2) - count($proven)));

        foreach ($tagged as $card) {
            $skip[$card->id] = true;
        }

        // The pool is already only what worthChoosing() keeps, so this takes
        // what is missing rather than the twice-that it drew when half of an
        // untagged draw was thrown away afterwards.
        $untagged = $this->sample($pool['other'], $skip, self::POOL - count($tagged) - count($proven));

        $cards = [...$proven, ...$tagged, ...$untagged];
        shuffle($cards);

        return $cards;
    }

    /**
     * Up to `$take` random cards from these pool rows, skipping the ids given.
     *
     * @param  list<array{id: int, price: int|null, tags: list<string>, crowd: list<string>, guessed: list<string>}>  $rows
     * @param  array<int, mixed>  $skip  ids as keys
     * @return list<TasteCard>
     */
    private function sample(array $rows, array $skip, int $take): array
    {
        if ($take <= 0) {
            return [];
        }

        shuffle($rows);

        $cards = [];

        foreach ($rows as $row) {
            if (isset($skip[$row['id']])) {
                continue;
            }

            $cards[] = self::card($row);

            if (count($cards) >= $take) {
                break;
            }
        }

        return $cards;
    }

    /**
     * What the deck draws from in one market: a random few thousand of its
     * giftable products, as plain rows with only what composing needs (id,
     * price, tags, the interests read from the title), cached POOL_TTL.
     *
     * Plain arrays, not models: the cache store refuses to rebuild objects
     * (`serializable_classes` is false), so a cached model comes back broken.
     *
     * A sample rather than the whole catalogue, and fresh every ten minutes,
     * so the deck varies across sessions as it did when every request drew
     * its own; twenty cards a session out of thousands never feels repeated.
     * The work the per-request draw did, the two random sorts and the
     * interest guessing, is paid once per window.
     *
     * @return array{tagged: list<array{id: int, price: int|null, tags: list<string>, crowd: list<string>, guessed: list<string>}>, other: list<array{id: int, price: int|null, tags: list<string>, crowd: list<string>, guessed: list<string>}>}
     */
    private function pool(Market $market): array
    {
        return Cache::remember('bc:taste-deck:pool:'.$market->value, self::POOL_TTL, function () use ($market): array {
            $interests = '{'.implode(',', array_map(
                fn (string $v) => '"'.GiftTags::interest($v).'"',
                Interest::values(),
            )).'}';

            /*
             * The operator `?|` (written `??|` past PDO), which the GIN
             * indexes on both tag columns serve. The `::text like` it
             * replaces read every giftable row. See SearchService's tag
             * filter for the same change.
             */
            $tagged = $this->base($market, [])
                ->where(fn (Builder $q) => $q
                    ->whereRaw('product_groups.gift_tags ??| ?::text[]', [$interests])
                    ->orWhereRaw('product_groups.crowd_tags ??| ?::text[]', [$interests]))
                ->inRandomOrder()
                ->limit(self::POOL_TAGGED)
                ->get(self::POOL_COLUMNS);

            $other = $this->base($market, [])
                ->inRandomOrder()
                ->limit(self::POOL_OTHER)
                ->get(self::POOL_COLUMNS);

            return [
                'tagged' => $this->worthChoosing($tagged),
                'other' => $this->worthChoosing($other),
            ];
        });
    }

    /** The columns a pool row is built from: nothing a card prints. */
    private const POOL_COLUMNS = ['id', 'title', 'category', 'min_price', 'gift_tags', 'crowd_tags'];

    /**
     * Only what can teach something and what somebody would unwrap, as pool
     * rows.
     *
     * Before 2026-09-26 a pair could be a cooker-hood part against a phone
     * case: `giftable` lets through parts, cases, cables and supplies, and a
     * product with no interest teaches nothing but a price. What the owner
     * saw was "not adapted": a vacuum nozzle, a blood-pressure meter, an
     * insect killer. Both tests read the product's own words (InterestGuesser).
     *
     * @param  iterable<ProductGroup>  $groups
     * @return list<array{id: int, price: int|null, tags: list<string>, crowd: list<string>, guessed: list<string>}>
     */
    private function worthChoosing(iterable $groups): array
    {
        $guesser = app(InterestGuesser::class);
        $rows = [];

        foreach ($groups as $group) {
            if ($guesser->isNotAGift((string) $group->title, $group->category)) {
                continue;
            }

            $card = TasteCard::fromGroup($group);

            if ($card->interests() === []) {
                continue;
            }

            $rows[] = [
                'id' => $card->id,
                'price' => $card->price,
                'tags' => $card->tags,
                'crowd' => $card->crowdTags,
                'guessed' => $card->guessed,
            ];
        }

        return $rows;
    }

    /** @param  array{id: int, price: int|null, tags: list<string>, crowd: list<string>, guessed: list<string>}  $row */
    private static function card(array $row): TasteCard
    {
        return new TasteCard(
            id: $row['id'],
            tags: $row['tags'],
            crowdTags: $row['crowd'],
            price: $row['price'],
            guessed: $row['guessed'],
        );
    }

    /**
     * A random few of the market's proven gifts (see PROVEN). None at all
     * until five people agree on anything, which on day one is the case.
     *
     * Read per request, by primary key: sixteen rows, and they must pass the
     * same filters the pool does.
     *
     * @param  list<int>  $exclude
     * @return list<TasteCard>
     */
    private function proven(Market $market, array $exclude): array
    {
        $ids = array_values(array_diff(
            app(CrowdPicks::class)->provenGifts($market, self::PROVEN_CANDIDATES),
            $exclude,
        ));

        if ($ids === []) {
            return [];
        }

        shuffle($ids);

        $rows = $this->worthChoosing($this->base($market, $exclude)
            ->whereIn('id', array_slice($ids, 0, self::PROVEN))
            ->get(self::POOL_COLUMNS));

        return array_map(self::card(...), $rows);
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
