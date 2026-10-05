<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\WishlistItem;
use App\Services\Wishlist\ListBudget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "The next step": products that follow on from what a person was given.
 *
 * Fetches the candidates, and leaves every judgement to {@see NextStepScorer}.
 * Three ways in, one per reason the scorer knows:
 *
 * - `product_links`: products people keep on the same lists as a past gift;
 * - the same brand as a past gift;
 * - the complement word lists (resources/content/gift-complements.php) for
 *   the families a past gift's title belongs to.
 *
 * Retrieval and arithmetic only, a handful of indexed queries; no AI, so it
 * can run on a page view (the person's page, Find a gift) and in the
 * reminder job alike. See docs/features/gift-history.md.
 */
final class NextSteps
{
    /** The past gifts looked at: the most recent ones say the most. */
    private const ANCHORS = 6;

    /** Candidates fetched per way in, before scoring. */
    private const PER_SOURCE = 40;

    /** Complement words searched at once, so one busy family cannot make the query huge. */
    private const MAX_WORDS = 24;

    public function __construct(
        private readonly GiftHistory $history,
        private readonly NextStepScorer $scorer,
    ) {}

    /**
     * @param  list<PastGift>|null  $past  when the caller already has the history
     * @param  list<int>  $alsoExclude  more product ids to leave out (already on screen, already on the list)
     * @return list<array{step: NextStep, group: ProductGroup}>
     */
    public function forRecipient(Recipient $recipient, Market $market, int $limit = 4, ?array $past = null, array $alsoExclude = []): array
    {
        $past ??= $this->history->for($recipient);
        $anchors = array_slice($past, 0, self::ANCHORS);

        if ($anchors === []) {
            return [];
        }

        $exclude = [
            ...$this->history->excludedGroupIds($recipient, $past),
            ...$this->onTheirLists($recipient),
            ...$alsoExclude,
        ];

        // Their list's budget (2026-10-05); see ListBudget.
        $budget = app(ListBudget::class)->forRecipient($recipient)['max'];
        $year = CarbonImmutable::now()->year;

        /*
         * The ranked ids, kept an hour (speed wave 2, 2026-09-27).
         *
         * Three candidate queries, one of them a 24-word ILIKE, and the
         * scoring ran on every view of the person's page and every Find a gift
         * board for them. The answer only moves when what goes into it moves,
         * so all of that is in the key: the past gifts looked at, the budget,
         * everything excluded (their history, their lists, the board on
         * screen), the limit and the year the scorer weighs recency by. A
         * product that went out of stock inside the hour drops at the load
         * below, which re-applies `shown()`.
         *
         * Plain arrays only: the cache store refuses to rebuild objects.
         */
        $key = 'next-steps:'.$recipient->id.':'.$market->value.':'.$limit.':'.sha1((string) json_encode([
            // Everything of a past gift the fetch and the scorer read.
            array_map(fn (PastGift $g) => [$g->source, $g->title, $g->groupId, $g->year, $g->brand, $g->category], $anchors),
            $budget,
            $year,
            collect($exclude)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
        ]));

        $groups = null;

        /** @var list<array{groupId: int, score: float, reason: string, after: string}> $ranked */
        $ranked = Cache::remember($key, 3600, function () use ($anchors, $exclude, $budget, $market, $limit, $year, &$groups): array {
            [$steps, $groups] = $this->rank($anchors, $exclude, $budget, $market, $limit, $year);

            return array_map(fn (NextStep $step) => [
                'groupId' => $step->groupId,
                'score' => $step->score,
                'reason' => $step->reason,
                'after' => $step->after,
            ], $steps);
        });

        if ($ranked === []) {
            return [];
        }

        $groups ??= $this->shown($market)
            ->whereIn('id', array_column($ranked, 'groupId'))
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($ranked as $row) {
            if (isset($groups[$row['groupId']])) {
                $out[] = [
                    'step' => new NextStep($row['groupId'], (float) $row['score'], $row['reason'], $row['after']),
                    'group' => $groups[$row['groupId']],
                ];
            }
        }

        return $out;
    }

    /**
     * Fetch, score and rank: the work {@see forRecipient()} keeps an hour.
     *
     * @param  list<PastGift>  $anchors
     * @param  list<int>  $exclude
     * @return array{0: list<NextStep>, 1: Collection<int, ProductGroup>}
     */
    private function rank(array $anchors, array $exclude, ?int $budget, Market $market, int $limit, int $year): array
    {
        $families = self::families();
        $pastIds = array_values(array_filter(array_map(fn (PastGift $g) => $g->groupId, $anchors)));

        $links = $this->links($pastIds);

        $ids = [
            ...array_keys($links),
            ...$this->sameBrand($anchors, $market, $budget),
            ...$this->complements($anchors, $families, $market, $budget),
        ];

        $ids = array_values(array_diff(array_unique($ids), $exclude));

        if ($ids === []) {
            return [[], collect()];
        }

        $groups = $this->shown($market)->whereIn('id', $ids)->get()->keyBy('id');

        $candidates = $groups->map(fn (ProductGroup $g) => new NextStepCandidate(
            groupId: $g->id,
            title: $g->title,
            brand: $g->brand,
            category: $g->category,
            price: $g->min_price,
        ))->values()->all();

        $steps = $this->scorer->rank(
            past: $anchors,
            candidates: $candidates,
            links: $links,
            families: $families,
            exclude: $exclude,
            budgetMax: $budget,
            thisYear: $year,
            limit: $limit,
        );

        return [$steps, $groups];
    }

    /**
     * The same, shaped for a page: a card per idea, with the past gift it
     * follows and why, as translation keys the page words itself.
     *
     * @param  list<PastGift>|null  $past
     * @param  list<int>  $alsoExclude
     * @return list<array<string, mixed>>
     */
    public function cards(Recipient $recipient, Market $market, int $limit = 4, ?array $past = null, array $alsoExclude = []): array
    {
        return array_map(fn (array $pick) => [
            'id' => $pick['group']->id,
            'title' => $pick['group']->displayTitle(),
            'brand' => $pick['group']->brand,
            'image' => $pick['group']->image_url,
            'price' => $pick['group']->min_price,
            'url' => $pick['group']->path(),
            'reason' => $pick['step']->reason,
            'after' => $pick['step']->after,
        ], $this->forRecipient($recipient, $market, $limit, $past, $alsoExclude));
    }

    /** @return array<string, array{triggers: list<string>, goes_with: list<string>}> */
    public static function families(): array
    {
        static $families = null;

        return $families ??= (array) require resource_path('content/gift-complements.php');
    }

    /**
     * What can be shown: in stock, priced, pictured, not merged away, and
     * either a gift by the catalogue's own judgement or worth showing at all.
     * Coffee beans are often not classed a gift on their own; after a moka pot
     * they are exactly one, so `worth_showing` is enough.
     *
     * @return Builder<ProductGroup>
     */
    private function shown(Market $market): Builder
    {
        return ProductGroup::query()
            ->forMarket($market)
            ->presentable()
            ->whereNull('merged_into_id')
            ->where(fn ($q) => $q->where('giftable', true)->orWhere('worth_showing', true));
    }

    /**
     * Products on the same lists as the past gifts, by how many people.
     *
     * @param  list<int>  $pastIds
     * @return array<int, array<int, int>> candidate => [past gift => people]
     */
    private function links(array $pastIds): array
    {
        if ($pastIds === []) {
            return [];
        }

        $links = [];

        $rows = DB::table('product_links')
            ->whereIn('group_a', $pastIds)
            ->orWhereIn('group_b', $pastIds)
            ->orderByDesc('owners')
            ->limit(self::PER_SOURCE * 2)
            ->get(['group_a', 'group_b', 'owners']);

        foreach ($rows as $row) {
            $a = (int) $row->group_a;
            $b = (int) $row->group_b;

            foreach ([[$a, $b], [$b, $a]] as [$past, $other]) {
                if (in_array($past, $pastIds, true) && ! in_array($other, $pastIds, true)) {
                    $links[$other][$past] = max($links[$other][$past] ?? 0, (int) $row->owners);
                }
            }
        }

        return $links;
    }

    /**
     * @param  list<PastGift>  $anchors
     * @return list<int>
     */
    private function sameBrand(array $anchors, Market $market, ?int $budget): array
    {
        $brands = array_values(array_unique(array_filter(array_map(
            fn (PastGift $g) => $g->brand === null ? null : mb_strtolower(trim($g->brand)),
            $anchors,
        ))));

        if ($brands === []) {
            return [];
        }

        return $this->shown($market)
            ->whereIn(DB::raw('lower(brand)'), $brands)
            ->when($budget !== null, fn ($q) => $q->where('min_price', '<=', $budget))
            ->orderByDesc('merchant_count')
            ->orderBy('id')
            ->limit(self::PER_SOURCE)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Products whose titles name something that goes with a past gift.
     *
     * ILIKE on the title, which the trigram index serves; the scorer checks
     * the word boundary afterwards, so "tea" finding "steak" here costs a
     * candidate, not a wrong suggestion.
     *
     * @param  list<PastGift>  $anchors
     * @param  array<string, array{triggers: list<string>, goes_with: list<string>}>  $families
     * @return list<int>
     */
    private function complements(array $anchors, array $families, Market $market, ?int $budget): array
    {
        $words = [];

        foreach ($anchors as $gift) {
            foreach ($this->scorer->familiesOf($gift->title, $families) as $family) {
                array_push($words, ...$families[$family]['goes_with']);
            }
        }

        $words = array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);

        if ($words === []) {
            return [];
        }

        return $this->shown($market)
            ->where(function ($q) use ($words): void {
                foreach ($words as $word) {
                    $q->orWhere('title', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word).'%');
                }
            })
            ->when($budget !== null, fn ($q) => $q->where('min_price', '<=', $budget))
            ->orderByDesc('merchant_count')
            ->orderBy('id')
            ->limit(self::PER_SOURCE)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * What is already on the owner's lists for this person: shown there, so
     * not suggested here.
     *
     * @return list<int>
     */
    private function onTheirLists(Recipient $recipient): array
    {
        return WishlistItem::query()
            ->whereNotNull('group_id')
            ->whereIn('wishlist_id', $recipient->wishlists()->select('id'))
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
