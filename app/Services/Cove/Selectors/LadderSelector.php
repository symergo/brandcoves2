<?php

declare(strict_types=1);

namespace App\Services\Cove\Selectors;

use App\Models\CovePlan;
use App\Models\ProductGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The shortlist behind a buying guide: one product per brand, cheapest first.
 *
 * Lifted from `GuideBuilder::shortlist()` unchanged, because it was already the
 * right answer for the kind of page it serves and the fold gave it a second
 * caller — the curation screen, which now suggests a guide's products to a
 * person before anything is written.
 *
 * Chosen for usefulness, not for score. A guide that lists seven versions of the
 * same thing at seven prices is the same failure the gift engine's MMR exists to
 * prevent: it looks like a comparison and offers no choice.
 */
class LadderSelector implements CoveSelector
{
    /**
     * @param  Collection<int, ProductGroup>  $curated
     * @return list<ProductGroup>
     */
    public function select(CovePlan $plan, Collection $curated, int $count, array $exclude = []): array
    {
        $lead = $curated->take($count)->values();
        $wanted = $count - $lead->count();

        if ($wanted < 1) {
            return $lead->all();
        }

        $terms = $this->terms($plan);

        if ($terms === []) {
            // Nothing to search on. A guide with only its curated products is a
            // short guide; a guide padded from a blank query is a wrong one.
            return $lead->all();
        }

        /*
         * The curator's brands are already spent.
         *
         * One product per brand is a rule about the whole page, not about the
         * half of it the engine chose — a curated Sony and an engine-picked Sony
         * is the repetition the rule exists to prevent.
         */
        $spent = $lead->map(fn (ProductGroup $g) => $g->brand ?? 'unbranded')->flip()->all();
        $skip = [...$exclude, ...$lead->pluck('id')->all()];

        $candidates = ProductGroup::query()
            ->forMarket($plan->market)
            ->presentable()
            ->when($skip !== [], fn ($q) => $q->whereNotIn('product_groups.id', $skip))
            ->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('products')
                ->whereColumn('products.group_id', 'product_groups.id')
                ->where('products.status', 'active')
                ->whereRaw(
                    // Bound, not read off the row — a row-dependent config makes
                    // the tsquery non-constant and puts this back on a sequential
                    // scan. See TopicMiner::availableProducts.
                    'products.search_vector @@ websearch_to_tsquery(bc_text_config(?), ?)',
                    [$plan->market->value, implode(' OR ', $terms)]
                ))
            // Comparable first: the reason to read this guide here rather than
            // anywhere else is that every entry carries several shops' prices.
            ->orderByDesc('merchant_count')
            ->orderByRaw('word_similarity(?, product_groups.title) DESC', [$terms[0]])
            // Over-fetched, because the brand pass below throws most of it away.
            ->limit(60)
            ->get();

        $picked = [];
        $brands = $spent;

        foreach ($candidates as $group) {
            /*
             * Unbranded products share one slot between them.
             *
             * Treating each as its own brand would let a shelf of white-label
             * listings fill the entire guide, which is precisely the outcome
             * the one-per-brand rule exists to prevent.
             */
            $brand = $group->brand ?? 'unbranded';

            if (isset($brands[$brand])) {
                continue;
            }

            $brands[$brand] = true;
            $picked[] = $group;

            if (count($picked) === $wanted) {
                break;
            }
        }

        // Price order, so the guide reads as a ladder rather than as a ranking
        // we would then have to defend. Applied to the engine's half only: the
        // curator's order is an editorial decision and outranks the ladder.
        usort($picked, fn (ProductGroup $a, ProductGroup $b) => ($a->min_price ?? 0) <=> ($b->min_price ?? 0));

        return [...$lead->all(), ...$picked];
    }

    /**
     * The words this guide is about.
     *
     * The title alone is not enough, and a seasonal Cove is where that shows up
     * hardest: "kamperen" is a theme, and no product title contains it — the
     * products are tents and sleeping bags. Shortlisting on the theme alone
     * found nothing and the guide was silently skipped as "too few products",
     * which is indistinguishable from a genuinely thin catalogue.
     *
     * `queries` are the phrases the topic is *made of*: what people actually
     * typed for a mined topic, and the product words the calendar supplies for a
     * seasonal one. Either way they are what the shelf is described with.
     *
     * The keyphrase leads when there is one, because it is the phrase the page
     * is written to answer; the title is a headline and may be nothing a product
     * is called.
     *
     * @return list<string>
     */
    private function terms(CovePlan $plan): array
    {
        $topic = filled($plan->focus_keyphrase) ? (string) $plan->focus_keyphrase : (string) $plan->title;

        return array_values(array_unique(array_filter(array_map(
            'trim',
            [$topic, ...array_filter((array) $plan->queries, 'is_string')],
        ))));
    }
}
