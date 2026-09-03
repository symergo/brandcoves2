<?php

declare(strict_types=1);

namespace App\Services\Cove\Selectors;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\CovePlan;
use App\Models\ProductGroup;
use App\Services\Cove\ObservanceCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Daily Cove's finds: themed first, surprising underneath, spread for variety.
 *
 * Lifted from `EditionBuilder::finds()`, minus the curation half — curation is
 * the same for every kind and stayed in the builder. What is here is the part
 * that is specifically a *column*: it ranks for the opposite of what retailers
 * rank for, it refuses to repeat itself, and it would rather be varied than
 * highest-scoring.
 *
 * Used by Daily Coves and gift personas. A guide uses {@see LadderSelector}
 * instead, because a comparison and a column want different things.
 */
class SurpriseSelector implements CoveSelector
{
    public function __construct(private readonly ObservanceCalendar $calendar) {}

    /**
     * @param  Collection<int, ProductGroup>  $curated
     * @return list<ProductGroup>
     */
    public function select(CovePlan $plan, Collection $curated, int $count, array $exclude = []): array
    {
        $market = $plan->market;
        $recent = $this->recent()->merge($exclude);

        $queries = array_values(array_unique([
            ...array_filter((array) $plan->queries, 'is_string'),
            ...$this->observanceQueries($plan),
        ]));

        /*
         * Curated products are exempt from the repeat memory but excluded from
         * the themed lane, so the engine never offers back something already on
         * the page. The entire point of curation is to override a score, so a
         * pick the ranker could veto would not be curation.
         */
        $themed = $queries === []
            ? collect()
            : $this->matching($market, $queries, $recent->merge($curated->pluck('id')), $count);

        $rest = ProductGroup::query()
            ->forMarket($market)
            ->presentable()
            ->where('surprise_score', '>', 0)
            ->whereNotIn('id', $recent)
            ->when($themed->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $themed->pluck('id')))
            ->orderByDesc('surprise_score')
            // Three times the target, so the set can be trimmed for variety
            // without dropping to the bottom of the ranking.
            ->limit($count * 3)
            ->get();

        /*
         * Themed finds lead; the rest fill the edition.
         *
         * A bias, not a filter. An edition that can only show pet products on a
         * thin catalogue day is an edition that fails to publish, and a page
         * that did not appear is worse than one where two of seven finds are
         * off-theme.
         *
         * Curated first, then themed, then the rest. `spread` trims for category
         * variety but never reorders, so a curated product keeps its place at
         * the top of the edition.
         */
        return $this->spread($curated->concat($themed)->concat($rest)->unique('id')->all(), $count);
    }

    /**
     * Products a recent edition already showed.
     *
     * The rolling memory is what makes this a column rather than a feed.
     * Repeating a product inside three months is the single clearest signal that
     * nobody is choosing these — and it is the first thing a returning visitor
     * notices, because they remember the odd ones.
     *
     * @return Collection<int, int>
     */
    private function recent(): Collection
    {
        return DB::table('daily_picks')
            ->join('daily_pick_sets', 'daily_pick_sets.id', '=', 'daily_picks.set_id')
            /*
             * Dailies only.
             *
             * A gift persona is a permanent page built once and rarely, and a
             * guide is an argument that is supposed to name the obvious
             * contenders. Letting either into the rolling memory would strip
             * whatever is on them out of the next three months of editions, for
             * no reader-visible benefit — nobody experiences a persona and a
             * Tuesday as the same column.
             */
            ->where('daily_pick_sets.kind', CoveKind::Daily->value)
            ->where('daily_picks.created_at', '>=', now()->subDays((int) config('giftcoves.picks.memory_days')))
            ->whereNotNull('daily_picks.group_id')
            ->pluck('daily_picks.group_id');
    }

    /**
     * The day's occasion, as product words.
     *
     * Resolved here rather than passed in, so that the curation screen — which
     * suggests products for a plan that has not been built — gets exactly what
     * the build will get. A dateless kind has no occasion and simply gets none.
     *
     * @return list<string>
     */
    private function observanceQueries(CovePlan $plan): array
    {
        if ($plan->drop_date === null) {
            return [];
        }

        $observance = $this->calendar->themeFor(
            CarbonImmutable::instance($plan->drop_date),
            $plan->market,
        );

        return $observance?->queries ?? [];
    }

    /**
     * Products that match the day's theme.
     *
     * Still gated on `surprise_score`: the point of the Cove is the find, and a
     * themed day is a lens on that rather than a licence to show the obvious pet
     * bed everyone has seen.
     *
     * @param  list<string>  $queries
     * @param  Collection<int, int>  $recent
     * @return Collection<int, ProductGroup>
     */
    private function matching(Market $market, array $queries, Collection $recent, int $count): Collection
    {
        $tsquery = implode(' OR ', array_map('trim', $queries));

        return ProductGroup::query()
            ->forMarket($market)
            ->presentable()
            ->where('surprise_score', '>', 0)
            ->whereNotIn('id', $recent)
            ->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('products')
                ->whereColumn('products.group_id', 'product_groups.id')
                ->where('products.status', 'active')
                ->whereRaw(
                    // The config is BOUND, not read off the row. Taken from
                    // `products.market` the tsquery is not constant, Postgres
                    // cannot use products_search_vector_idx, and it evaluates
                    // the match once per candidate group instead — 10s against
                    // be-nl where the index does it in 7ms. One market is
                    // already the scope here. See TopicMiner::availableProducts.
                    'products.search_vector @@ websearch_to_tsquery(bc_text_config(?), ?)',
                    [$market->value, $tsquery]
                ))
            ->orderByDesc('surprise_score')
            ->limit($count * 2)
            ->get();
    }

    /**
     * Trim a ranked list to one per category where possible.
     *
     * Seven finds that all come from the same corner of the catalogue is a
     * narrower day than seven from seven corners, even when the narrow set
     * scores higher. Same reasoning as the gift engine's MMR, applied more
     * simply because the ranking here is one-dimensional.
     *
     * @param  list<ProductGroup>  $ranked
     * @return list<ProductGroup>
     */
    private function spread(array $ranked, int $count): array
    {
        $picked = [];
        $seen = [];

        foreach ($ranked as $group) {
            $key = $group->category ?? 'unknown';

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $picked[] = $group;

            if (count($picked) === $count) {
                return $picked;
            }
        }

        // Backfill from the remainder if the catalogue genuinely lacks the
        // variety — a short edition is worse than a slightly repetitive one.
        foreach ($ranked as $group) {
            if (count($picked) === $count) {
                break;
            }

            if (! in_array($group, $picked, true)) {
                $picked[] = $group;
            }
        }

        return $picked;
    }
}
