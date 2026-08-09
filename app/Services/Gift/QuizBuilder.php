<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Support\Collection;

/**
 * Turn a list into a quiz.
 *
 * Pure enough to test properly: a list and a candidate pool in, a set of rounds
 * out. No routing, no scoring of players, no persistence.
 *
 * ## The decision that makes or breaks it
 *
 * A quiz is only a quiz if the wrong answers are plausible. Drawing random
 * giftable products makes every round trivial — nobody confuses a chainsaw for a
 * pair of earrings — and **a game you cannot lose is not worth sharing**, which
 * removes the entire reason the feature exists.
 *
 * So distractors are near-misses, chosen with the *same* similarity function the
 * engine uses to break up near-duplicates. That function exists to push similar
 * things apart; here it is run backwards to pull them together. Reusing it means
 * there is one notion of "alike" in the codebase rather than two that can drift.
 */
class QuizBuilder
{
    /** Below this a quiz is a formality rather than a game. */
    public const MIN_ITEMS = 5;

    public const ROUNDS = 5;

    private const OPTIONS_PER_ROUND = 4;

    /**
     * How alike a distractor must be to the answer before it is worth offering.
     *
     * Measured on the same 0–1 scale as the engine's MMR similarity. Too low and
     * the round gives itself away; too high and there are not enough candidates
     * in a modest catalogue to fill it.
     */
    private const MIN_SIMILARITY = 0.15;

    /**
     * @param  Collection<int, ProductGroup>  $pool  candidates, already filtered to giftable + in-market
     * @param  (callable(list<mixed>): list<mixed>)|null  $shuffle  injectable for deterministic tests
     * @return list<array{answer: int, title: string, options: list<array{id: int, title: string, image: string|null}>}>
     */
    public function build(Wishlist $list, Collection $pool, ?callable $shuffle = null): array
    {
        $shuffle ??= static function (array $items): array {
            shuffle($items);

            return $items;
        };

        $answers = $list->items
            ->filter(fn (WishlistItem $item) => $item->group !== null && ! $item->rendersLive())
            ->values();

        if ($answers->count() < self::MIN_ITEMS) {
            // Do not ship a two-question quiz. `DigestBuilder` refuses to send an
            // empty digest for the same reason: a thin version teaches people the
            // thing is not worth opening, which is the one irreversible thing a
            // shareable artefact can do.
            return [];
        }

        $onTheList = $answers->pluck('group_id')->all();
        $rounds = [];

        foreach (array_slice($shuffle($answers->all()), 0, self::ROUNDS) as $item) {
            /** @var ProductGroup $answer */
            $answer = $item->group;

            $distractors = $this->distractors($answer, $pool, $onTheList);

            if (count($distractors) < self::OPTIONS_PER_ROUND - 1) {
                // A round with two options is a coin toss. Skipping it is better
                // than padding it with something obviously wrong.
                continue;
            }

            $options = $shuffle([
                $this->option($answer),
                ...array_map($this->option(...), $distractors),
            ]);

            $rounds[] = [
                'answer' => $answer->id,
                'title' => $answer->title,
                'options' => array_values($options),
            ];
        }

        return $rounds;
    }

    /**
     * The wrong answers.
     *
     * Sorted by similarity to the real one, hardest first — and excluding
     * anything actually on the list, which would make two options both correct.
     *
     * @param  Collection<int, ProductGroup>  $pool
     * @param  list<int>  $onTheList
     * @return list<ProductGroup>
     */
    private function distractors(ProductGroup $answer, Collection $pool, array $onTheList): array
    {
        return $pool
            ->reject(fn (ProductGroup $candidate) => in_array($candidate->id, $onTheList, true))
            ->map(fn (ProductGroup $candidate) => [
                'group' => $candidate,
                'similarity' => $this->similarity($answer, $candidate),
            ])
            ->filter(fn (array $scored) => $scored['similarity'] >= self::MIN_SIMILARITY)
            ->sortByDesc('similarity')
            ->take(self::OPTIONS_PER_ROUND - 1)
            ->pluck('group')
            ->values()
            ->all();
    }

    /**
     * How alike two products are, 0 to 1.
     *
     * Same weights as the engine's diversifier: category dominates because it is
     * what a person notices, brand adds a little, and title overlap catches the
     * rest — which matters most where the feed's category field is empty, which
     * is often.
     */
    private function similarity(ProductGroup $a, ProductGroup $b): float
    {
        $score = 0.0;

        if ($a->category !== null && $a->category === $b->category) {
            $score += 0.6;
        }

        if ($a->brand !== null && $a->brand === $b->brand) {
            $score += 0.2;
        }

        $score += 0.4 * $this->titleOverlap($a->title, $b->title);

        // A near-identical price is its own kind of plausible: two things at
        // €39.95 look like alternatives, whatever they are.
        if ($a->min_price !== null && $b->min_price !== null && $a->min_price > 0) {
            $ratio = abs($a->min_price - $b->min_price) / $a->min_price;

            if ($ratio <= 0.25) {
                $score += 0.15;
            }
        }

        return min(1.0, $score);
    }

    private function titleOverlap(string $left, string $right): float
    {
        $tokenise = static function (string $text): array {
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) > 2)));
        };

        $a = $tokenise($left);
        $b = $tokenise($right);

        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / count(array_unique([...$a, ...$b]));
    }

    /** @return array{id: int, title: string, image: string|null} */
    private function option(ProductGroup $group): array
    {
        return [
            'id' => $group->id,
            'title' => $group->title,
            'image' => $group->image_url,
        ];
    }
}
