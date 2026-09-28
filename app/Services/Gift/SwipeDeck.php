<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use App\Models\ProductGroup;
use Illuminate\Support\Facades\DB;

/**
 * Which products to show next in Swipe gifts: one card at a time, right for
 * "on the list", left for "no", for as long as the visitor keeps going.
 *
 * The owner asked for it on 2026-09-28 as a way to build a list, with no
 * limit and a way to stop. So there is no round count here, unlike This or
 * that (TasteDeck::ROUNDS): the page asks for another batch whenever it runs
 * low, and only an empty answer ends it.
 *
 * ## What a swipe teaches
 *
 * An interest on a card swiped right scores +1 (at the card's own trust per
 * tag, TasteCard::values()); on a card swiped left, -0.5. A left swipe counts
 * less because "not this product" is weaker evidence than "yes, this": a no
 * to one ugly mug is not a no to coffee. Two noes and no yes (-1.0) and the
 * interest is left out from then on.
 *
 * ## What comes next
 *
 * Before anything is liked every card explores: each one shows the interest
 * seen least so far. Once something is liked, two cards in three follow a
 * favourite (up to three, in turn) near the price of what was liked, and the
 * third keeps exploring, so a visitor who liked a coffee grinder early is not
 * shown only coffee for the next hour.
 *
 * The draw is TasteDeck's random pool (the same presentable, giftable,
 * interest-bearing products), topped up per favourite straight from the tag
 * indexes, because a random 160 may hold two cards of a given interest and a
 * favourite deserves more than two.
 *
 * No AI (invariant 1): a random draw and arithmetic.
 */
final class SwipeDeck
{
    /** Cards per request. The page asks for more while three are still left. */
    public const BATCH = 8;

    /** Of every three cards once something is liked, the third explores. */
    private const EXPLORE_EVERY = 3;

    /** What a left swipe counts for each of the card's interests. */
    private const PASS_WEIGHT = 0.5;

    /** At or below this an interest is left out: two noes and no yes. */
    private const AVOID_AT = -1.0;

    /** An interest needs a full like to be followed. */
    private const LEAD_AT = 1.0;

    private const LEADERS = 3;

    /**
     * "Near the price" of what was liked: half to twice the median. Wide on
     * purpose; the price is a preference to lean on, not a filter, and a
     * card outside the band is still used when nothing inside it is left.
     */
    private const PRICE_LOW = 0.5;

    private const PRICE_HIGH = 2.0;

    /** Products fetched per favourite, on top of the random pool. */
    private const TOP_UP = 24;

    public function __construct(private readonly TasteDeck $deck) {}

    /**
     * @param  list<int>  $yes  swiped right
     * @param  list<int>  $no  swiped left
     * @param  list<int>  $exclude  anything else already shown or queued
     * @return list<ProductGroup>
     */
    public function next(Market $market, array $yes, array $no, array $exclude, int $count = self::BATCH): array
    {
        $judged = $this->cards($market, [...$yes, ...$no]);
        $liked = array_values(array_filter(array_map(fn (int $id) => $judged[$id] ?? null, $yes)));
        $passed = array_values(array_filter(array_map(fn (int $id) => $judged[$id] ?? null, $no)));

        $skip = array_values(array_unique([...$exclude, ...$yes, ...$no]));
        $leaders = self::leaders(self::scores($liked, $passed));

        $pool = [];

        foreach ([...$this->favourites($market, $leaders, $skip), ...$this->deck->draw($market, $skip)] as $card) {
            $pool[$card->id] ??= $card;
        }

        $picked = $this->compose(array_values($pool), $liked, $passed, $count);

        /*
         * Only the products shown are loaded, with the columns a card prints,
         * and presentable again: the pool is up to ten minutes old, and a
         * product that went out of stock since is dropped rather than shown.
         */
        $ids = array_map(fn (TasteCard $c) => $c->id, $picked);

        $byId = $ids === [] ? collect() : ProductGroup::query()
            ->presentable()
            ->whereIn('id', $ids)
            ->get(['id', 'market', 'title', 'display_title', 'brand', 'image_url', 'min_price'])
            ->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id) => $byId->get($id), $ids)));
    }

    /**
     * The pure half: the next cards out of a pool, given the swipes so far.
     *
     * The pool's order is the tie-breaker, and it arrives shuffled, so the
     * deck is random where the rules leave a choice and fixed in a test.
     *
     * @param  list<TasteCard>  $pool
     * @param  list<TasteCard>  $liked
     * @param  list<TasteCard>  $passed
     * @return list<TasteCard>
     */
    public function compose(array $pool, array $liked, array $passed, int $count): array
    {
        $scores = self::scores($liked, $passed);
        $leaders = self::leaders($scores);
        $avoid = array_keys(array_filter($scores, fn (float $s) => $s <= self::AVOID_AT));
        $price = self::pricePoint($liked);

        // How often each interest has been in front of the visitor, this batch included.
        $seen = [];

        foreach ([...$liked, ...$passed] as $card) {
            foreach ($card->interests() as $interest) {
                $seen[$interest] = ($seen[$interest] ?? 0) + 1;
            }
        }

        // Never an avoided interest, not even as the last card left.
        $pool = array_values(array_filter(
            $pool,
            fn (TasteCard $c) => array_intersect($c->interests(), $avoid) === [],
        ));

        $out = [];
        $followed = 0;

        for ($i = 0; $i < $count && $pool !== []; $i++) {
            $card = null;

            if ($leaders !== [] && $i % self::EXPLORE_EVERY !== self::EXPLORE_EVERY - 1) {
                $card = $this->following($pool, $leaders[$followed % count($leaders)], $price);
                $followed++;
            }

            $card ??= $this->exploring($pool, $seen);

            $out[] = $card;
            $pool = array_values(array_filter($pool, fn (TasteCard $c) => $c->id !== $card->id));

            foreach ($card->interests() as $interest) {
                $seen[$interest] = ($seen[$interest] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * @param  list<TasteCard>  $liked
     * @param  list<TasteCard>  $passed
     * @return array<string, float>
     */
    public static function scores(array $liked, array $passed): array
    {
        $scores = [];

        foreach ($liked as $card) {
            foreach ($card->values(GiftTags::INTEREST) as $interest => $weight) {
                $scores[$interest] = ($scores[$interest] ?? 0.0) + $weight;
            }
        }

        foreach ($passed as $card) {
            foreach ($card->values(GiftTags::INTEREST) as $interest => $weight) {
                $scores[$interest] = ($scores[$interest] ?? 0.0) - $weight * self::PASS_WEIGHT;
            }
        }

        return $scores;
    }

    /**
     * @param  array<string, float>  $scores
     * @return list<string>
     */
    private static function leaders(array $scores): array
    {
        arsort($scores);

        return array_slice(array_map('strval', array_keys(array_filter($scores, fn (float $s) => $s >= self::LEAD_AT))), 0, self::LEADERS);
    }

    /** @param  list<TasteCard>  $liked */
    private static function pricePoint(array $liked): ?int
    {
        $prices = array_values(array_filter(array_map(fn (TasteCard $c) => $c->price, $liked), fn (?int $p) => $p !== null && $p > 0));

        if ($prices === []) {
            return null;
        }

        sort($prices);

        return $prices[intdiv(count($prices), 2)];
    }

    /**
     * The first card carrying this interest, near the price when one is.
     *
     * @param  list<TasteCard>  $pool
     */
    private function following(array $pool, string $interest, ?int $price): ?TasteCard
    {
        $carrying = array_values(array_filter($pool, fn (TasteCard $c) => in_array($interest, $c->interests(), true)));

        if ($carrying === []) {
            return null;
        }

        if ($price !== null) {
            foreach ($carrying as $card) {
                if ($card->price !== null && $card->price >= $price * self::PRICE_LOW && $card->price <= $price * self::PRICE_HIGH) {
                    return $card;
                }
            }
        }

        return $carrying[0];
    }

    /**
     * The card whose least-seen interest has been seen least. A card with no
     * interest at all comes last: it teaches nothing.
     *
     * @param  list<TasteCard>  $pool
     * @param  array<string, int>  $seen
     */
    private function exploring(array $pool, array $seen): TasteCard
    {
        $best = $pool[0];
        $bestKey = PHP_INT_MAX;

        foreach ($pool as $card) {
            $interests = $card->interests();
            $key = $interests === [] ? PHP_INT_MAX - 1 : min(array_map(fn (string $i) => $seen[$i] ?? 0, $interests));

            if ($key < $bestKey) {
                $best = $card;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * The swiped products as cards, keyed by id; only this market's.
     *
     * @param  list<int>  $ids
     * @return array<int, TasteCard>
     */
    private function cards(Market $market, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ProductGroup::query()
            ->forMarket($market)
            ->whereIn('id', array_values(array_unique($ids)))
            ->get(['id', 'title', 'category', 'min_price', 'gift_tags', 'crowd_tags'])
            ->mapWithKeys(fn (ProductGroup $g) => [(int) $g->id => TasteCard::fromGroup($g)])
            ->all();
    }

    /**
     * A random few products per favourite interest, read through the tag
     * indexes (`??|` is the `?|` operator, see SuggestionEngine).
     *
     * @param  list<string>  $leaders
     * @param  list<int>  $skip
     * @return list<TasteCard>
     */
    private function favourites(Market $market, array $leaders, array $skip): array
    {
        $cards = [];

        foreach ($leaders as $interest) {
            $tag = '{"'.GiftTags::interest($interest).'"}';

            $ids = DB::table('product_groups')->select('id')->where('market', $market->value)->whereRaw('gift_tags ??| ?::text[]', [$tag])
                ->union(DB::table('product_groups')->select('id')->where('market', $market->value)->whereRaw('crowd_tags ??| ?::text[]', [$tag]));

            $query = ProductGroup::query()
                ->forMarket($market)
                ->giftable()
                ->presentable()
                ->whereBetween('min_price', [(int) config('giftcoves.gift.min_price'), (int) config('giftcoves.gift.max_price')])
                ->whereIn('id', $ids);

            if ($skip !== []) {
                $query->whereNotIn('id', $skip);
            }

            foreach ($query->inRandomOrder()->limit(self::TOP_UP)->get(['id', 'title', 'category', 'min_price', 'gift_tags', 'crowd_tags']) as $group) {
                $cards[] = TasteCard::fromGroup($group);
            }
        }

        shuffle($cards);

        return $cards;
    }
}
