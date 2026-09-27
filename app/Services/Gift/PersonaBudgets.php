<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\CoveScene;
use App\Enums\Interest;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Ideas\OfflineIdeaPicker;
use App\Support\CurrentMarket;
use Illuminate\Support\Facades\Cache;

/**
 * A persona at three budgets: around 15, around 40, around 100
 * (the owner's request 7, 2026-09-26).
 *
 * The curated shelf is one editor's choice at whatever prices it came to.
 * Under it, three tabs answer "and if I mean to spend about this much?", each
 * the suggestion engine's answer to the persona's brief within that band, so
 * the page helps whatever the budget. The curated editorial is untouched.
 *
 * Retrieval and arithmetic, no AI, and cached a day per persona and build:
 * three engine runs per persona per day, not per visitor. See
 * docs/features/persona-budgets.md.
 */
class PersonaBudgets
{
    /**
     * The drawings that say what a persona is about when it has no brief.
     * Most scenes share a value with an interest; these few do not.
     */
    private const SCENE_INTERESTS = [
        'racing' => 'gaming',
        'dog' => 'pets',
        'plants' => 'gardening',
    ];

    public function __construct(
        private readonly SuggestionEngine $engine,
        private readonly InterestGuesser $guesser,
        private readonly OfflineIdeaPicker $ideas,
    ) {}

    /**
     * The bands and the offline ideas for one persona, ready for the page.
     *
     * @return array{bands: list<array{around: int, items: list<array<string, mixed>>}>, ideas: list<array{id: int, title: string}>}
     */
    public function for(DailyPickSet $persona, CurrentMarket $current): array
    {
        $persona->loadMissing(['plan', 'picks.group']);

        $key = implode(':', [
            'bc:persona-budgets',
            $persona->id,
            $persona->updated_at?->timestamp ?? 0,
            $persona->plan?->updated_at?->timestamp ?? 0,
        ]);

        return Cache::remember($key, (int) config('giftcoves.persona_budgets.cache_ttl', 86400), function () use ($persona, $current): array {
            $brief = $this->brief($persona);

            if ($brief === null) {
                return ['bands' => [], 'ideas' => []];
            }

            return [
                'bands' => $this->bands($persona, $brief, $current),
                'ideas' => $this->ideas->forBrief($brief),
            ];
        });
    }

    /**
     * Who the persona is for, as a brief the engine can answer.
     *
     * In order of how much a person decided it: the plan's own gift brief;
     * the drawing (a persona drawn with a coffee cup is about coffee, and one
     * drawn as "has everything" is exactly that); the interests its products
     * are tagged with; and last, the interests their titles point at. Null
     * when none of those says anything, and the page then shows no bands
     * rather than a budget browse wearing the persona's name.
     */
    public function brief(DailyPickSet $persona, int $limit = 4): ?TasteBrief
    {
        $persona->loadMissing(['plan', 'picks.group']);

        $planned = $persona->plan?->tasteBrief($limit);

        if ($planned !== null) {
            return $planned;
        }

        if ($persona->scene === CoveScene::HasEverything) {
            return new TasteBrief(market: $persona->market, limit: $limit, hasEverything: true);
        }

        $scene = $persona->scene?->value;
        $fromScene = $scene === null ? null : (self::SCENE_INTERESTS[$scene] ?? $scene);

        if ($fromScene !== null && Interest::tryFrom($fromScene) !== null) {
            return new TasteBrief(market: $persona->market, interests: [$fromScene], limit: $limit);
        }

        $groups = $persona->picks->pluck('group')->filter();

        $interests = $this->count($groups->all(), fn (ProductGroup $g) => array_map(
            fn (string $tag) => substr($tag, strlen(GiftTags::INTEREST) + 1),
            array_filter([...$g->giftTags(), ...$g->crowdTags()], fn (string $tag) => str_starts_with($tag, GiftTags::INTEREST.':')),
        ));

        if ($interests === []) {
            $interests = $this->count($groups->all(), fn (ProductGroup $g) => $this->guesser->interests((string) $g->title, $g->category));
        }

        // Two or more products agreeing, or it is one product's accident.
        $interests = array_keys(array_filter($interests, fn (int $n) => $n >= 2));

        return $interests === []
            ? null
            : new TasteBrief(market: $persona->market, interests: array_slice($interests, 0, 2), limit: $limit);
    }

    /**
     * Whether a suggestion belongs to the persona, rather than being the
     * engine's fallback.
     *
     * The engine answers a brief it cannot fill with a budget browse, so Find
     * a gift is never empty; a tab, or a top 10, of that would be the
     * persona's name on a random shelf. Shared with PersonaTopTen, so the two
     * sections under a persona agree on what belongs to it.
     */
    public static function fits(TasteBrief $brief, Suggestion $suggestion): bool
    {
        return ($brief->interests === [] && ! $brief->hasEverything)
            || $suggestion->matchedInterests !== []
            || $suggestion->consumable;
    }

    /**
     * @param  list<ProductGroup>  $groups
     * @param  callable(ProductGroup): list<string>  $interestsOf
     * @return array<string, int> interest => products, most first
     */
    private function count(array $groups, callable $interestsOf): array
    {
        $counts = [];

        foreach ($groups as $group) {
            foreach (array_unique($interestsOf($group)) as $interest) {
                if (Interest::tryFrom($interest) !== null) {
                    $counts[$interest] = ($counts[$interest] ?? 0) + 1;
                }
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @return list<array{around: int, items: list<array<string, mixed>>}>
     */
    private function bands(DailyPickSet $persona, TasteBrief $brief, CurrentMarket $current): array
    {
        $perBand = (int) config('giftcoves.persona_budgets.per_band', 6);
        $minimum = (int) config('giftcoves.persona_budgets.min_per_band', 3);

        // Not the products already on the page: a tab is more ideas, not the
        // same six again at their own prices.
        $shown = $persona->picks->pluck('group_id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        $bands = [];

        foreach ((array) config('giftcoves.persona_budgets.bands', []) as $band) {
            $min = (int) $band['min'];
            $max = (int) $band['max'];

            $items = [];

            foreach ($this->engine->suggest($brief->withBudget($min, $max)->withLimit($perBand)->excluding($shown)) as $suggestion) {
                $group = $suggestion->group;

                // The engine filters on price already; checked again because a
                // tab that says "around 15" and shows 40 is a broken promise.
                if (! self::fits($brief, $suggestion) || $group->min_price === null || $group->min_price < $min || $group->min_price > $max) {
                    continue;
                }

                $items[] = [
                    'groupId' => $group->id,
                    'title' => $group->displayTitle(),
                    'image' => $group->image_url,
                    'price' => $group->min_price,
                    'url' => $current->url("p/{$group->id}/{$group->slug}"),
                ];
            }

            if (count($items) >= $minimum) {
                $bands[] = ['around' => (int) $band['around'], 'items' => $items];
            }
        }

        return $bands;
    }
}
