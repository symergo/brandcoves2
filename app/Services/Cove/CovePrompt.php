<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\CovePlan;
use App\Models\CovePlanItem;
use App\Models\ProductGroup;
use App\Services\Ai\PromptBank;
use App\Services\Cove\Writers\GuideWriter;
use App\Services\Editorial\Allowlist;
use App\Services\Editorial\ProseCards;
use App\Services\Guides\CoveMarkup;
use Carbon\CarbonImmutable;

/**
 * What the writer is told about a Cove — whoever the writer is.
 *
 * The prompt is assembled from five sources and lived entirely inside
 * `EditionBuilder`'s private methods, which meant only the built-in model could
 * ever be given it. An external author had to be told the same rules out of
 * band, and they were: copied by hand into the API root's `writing` block,
 * `docs/publishing-guide.md`, `docs/features/scheduled-writing.md` and the seed
 * skill. Four copies of one contract, and they had already drifted — the API
 * root omitted the one-paragraph-per-product rule that `ProseCards` exists to
 * make undroppable, so an agent following the server's own description of itself
 * wrote prose that publishes with bare cards at the foot of the page.
 *
 * Extracted so both callers ask the same object:
 *
 *   - `EditionBuilder` when the model writes;
 *   - `GET /api/editorial/coves/{id}/brief` when somebody else does.
 *
 * The five layers, in the order they are concatenated:
 *
 * | Layer | Source | Editable? |
 * |---|---|---|
 * | Voice and rules, per kind | `PromptBank` → `Defaults::*_SYSTEM` | **yes**, Operations → Prompts |
 * | One paragraph per product | `ProseCards::promptContract()` | no |
 * | The two curated rules | here | no |
 * | Link tokens + this piece's allowlist | `CoveMarkup::promptContract()` | no |
 * | The brief itself | the plan, bound into the kind's user template | n/a |
 *
 * The uneditable layers are uneditable on purpose: a prompt-bank edit may change
 * the voice and must not be able to drop the rules that decide whether the page
 * renders correctly.
 */
final readonly class CovePrompt
{
    public function __construct(
        private PromptBank $prompts,
        private CoveMarkup $markup,
        private Allowlist $allowlist,
        private ObservanceCalendar $calendar,
        private GuideWriter $guides,
    ) {}

    /**
     * Both halves, exactly as the builder would send them for this plan.
     *
     * The one method the editorial API needs, and the reason a test can assert
     * that what is served and what is used are the same string.
     *
     * `$finds` is what the page will actually be about. For a `locked` plan that
     * is the curated shortlist and this is exact; for an `open` one the engine
     * adds more on the day, so the caller passes what it knows and the result is
     * as good as the information available — the same caveat the editorial API
     * already documents for `linkCheck` on a plan.
     *
     * @param  list<ProductGroup>  $finds
     * @return array{system: string, user: string}
     */
    public function forPlan(CovePlan $plan, array $finds): array
    {
        $brief = $this->brief($plan);
        $observance = $this->observanceFor($plan);

        /*
         * No observance here, mirroring `EditionBuilder::editorial()` exactly.
         * It reaches the *brief* below and not the allowlist, which is what the
         * builder does — and this method's entire value is being the same string.
         *
         * The plan's own edition is excluded: a published article that can link
         * to itself is a loop, and it is also what made the served prompt differ
         * from the built one on every rebuild — at first build the page does not
         * exist yet, so the difference only appeared the second time.
         */
        $allowed = $this->allowlist($plan->market, $finds, excludeGuideId: $plan->edition_id);

        /*
         * A body-writing kind is written by `GuideWriter`, which assembles its
         * own prompt — a different shape from the column's, and rightly so: a
         * guide argues product by product and its order *is* the ranking.
         *
         * Delegated rather than reimplemented. The first version of this class
         * assembled the column shape for every kind, and the byte-identical test
         * caught it immediately: an author writing a guide would have been given
         * the Daily's curated rules and a prompt the builder never sends.
         */
        if ($plan->kind->writesBody()) {
            return $this->guides->promptFor($plan, $finds, $allowed, $brief);
        }

        return [
            'system' => $this->system($plan->kind, $brief !== [], $allowed),
            'user' => $this->user(
                $plan->kind,
                $plan->market,
                $finds,
                $observance,
                (string) $plan->title,
                $brief,
                $plan,
            ),
        ];
    }

    /**
     * The system message: voice, then the rules no prompt edit may remove.
     *
     * @param  bool  $curated  Whether a person chose the products this is written about.
     *                         Adds the order and the notes; it does not decide
     *                         whether every product is covered. See below.
     * @param  array<string, mixed>  $allowed
     */
    public function system(CoveKind $kind, bool $curated, array $allowed): string
    {
        return $this->voice($kind, $curated)."\n\n".$this->markup->promptContract($allowed);
    }

    /**
     * The editable half plus the paragraph contract, without the allowlist.
     *
     * Split out because `EditionBuilder` composes these two in that order and a
     * second concatenation site is how the two halves come to disagree.
     */
    private function voice(CoveKind $kind, bool $curated): string
    {
        /*
         * The editable half, per kind.
         *
         * A Daily and a persona no longer share one: a persona written by the
         * column's prompt says "this week" on a page that is undated,
         * evergreen and read for years. See App\Services\Ai\Prompts\Defaults.
         */
        $base = $this->prompts->system('cove.'.$kind->value);

        /*
         * Every product gets written about. Curation adds two rules on top; it
         * no longer decides whether the rule exists.
         *
         * This used to flip. An engine-picked edition was told "pick two or
         * three worth a sentence and let the rest speak for themselves", and
         * that was right while the page was prose and then a grid: the grid
         * carried whatever the prose skipped, and writing about all seven read
         * as a catalogue with adjectives.
         *
         * It stopped being right when the card moved under the paragraph that
         * names it. A product no paragraph mentions now has nothing written
         * about it anywhere — it drops to the foot of the page as a bare card,
         * which is the shape the pairing exists to get away from. So the floor
         * is one passage per product whoever chose them, and what curation adds
         * is the order and the reasons.
         *
         * The paragraph rules themselves live on ProseCards, next to the walk
         * that makes them true, because the guide writer needs the same ones.
         * The curated pair below stays here: it is a fact about the plan in
         * front of the builder, which ProseCards knows nothing about.
         */
        $every = ProseCards::promptContract();

        return $base."\n".($curated
            ? $every."\n".<<<'TXT'
            - Take them in the order given: somebody chose that order.
            - The note beside a product is the reason it was chosen. Use it. Do not quote it.
            TXT
            : $every);
    }

    /**
     * The brief: the kind's own template with this piece's facts bound into it.
     *
     * @param  list<ProductGroup>  $finds
     * @param  list<array{id: int, title: string, note: string|null}>  $brief
     */
    public function user(
        CoveKind $kind,
        Market $market,
        array $finds,
        ?Observance $observance,
        string $title,
        array $brief = [],
        ?CovePlan $plan = null,
    ): string {
        $curatedIds = array_column($brief, 'id');
        $lines = [];

        foreach ($finds as $group) {
            // The engine's own finds only. A curated product is described
            // below, with its note, and listing it twice invites the model to
            // treat the two entries as two products.
            if (in_array($group->id, $curatedIds, true)) {
                continue;
            }

            /*
             * `id %d`, not `[[product:%d]]`. This list is the strongest example
             * the model ever sees of what a product token looks like, and while
             * it showed the bare form that is what came back in the prose — a
             * number in the middle of a sentence, because an unlabelled token
             * rendered as its id. Handing over the id as a plain fact leaves
             * `promptContract()` the only place the token shape is stated, and
             * there it is stated with its label required.
             */
            $lines[] = sprintf(
                '- id %d: %s (%s)',
                $group->id,
                $group->title,
                $group->category ?? 'uncategorised',
            );
        }

        $curated = array_map(
            fn (array $item, int $i) => sprintf(
                '%d. id %d: %s%s',
                $i + 1,
                $item['id'],
                $item['title'],
                $item['note'] === null ? '' : ' — why it is here: '.$item['note'],
            ),
            $brief,
            array_keys($brief),
        );

        return $this->prompts->user('cove.'.$kind->value, [
            'language' => $market->language(),
            'title' => $title,

            /*
             * An evergreen theme is NOT an occasion, and saying so matters:
             * told "the occasion: cosy", the model writes "today we celebrate
             * cosiness", inventing a holiday that does not exist.
             *
             * A persona never binds this at all — it is undated, so its
             * template does not offer the placeholder.
             */
            'occasion' => match (true) {
                $observance === null => null,
                $observance->evergreen => "Today's angle: {$observance->key}. This is NOT a named day or holiday — do not imply the date has any significance.",
                default => "The occasion: {$observance->key}.",
            },

            /*
             * The editor's direction for this build.
             *
             * In the user prompt rather than the system message, and that is
             * the point: the system message carries the rules that may not be
             * traded away — no prices, no invented claims, only the products
             * listed. An instruction arrives as part of the brief the writer
             * works to, underneath those, so "mention how cheap it is" cannot
             * become permission to.
             */
            'direction' => blank($plan?->build_instructions)
                ? null
                : "The editor's direction for this piece — follow it within the rules above:\n"
                    .trim((string) $plan->build_instructions),

            'curated' => $curated === []
                ? null
                : "The curated list — write about all of these, in this order:\n".implode("\n", $curated),

            'finds' => $lines === []
                ? null
                : ($curated === [] ? "Today's finds:\n" : "Also in the edition, if you want them:\n").implode("\n", $lines),
        ]);
    }

    /**
     * What the writer is allowed to link to.
     *
     * Everything in this edition, plus the brands behind it and the observance's
     * own queries. Nothing else — a token outside this list is stripped to plain
     * text by CoveMarkup, so the worst a hallucination costs is an unlinked
     * phrase rather than a 404 in the middle of an article.
     *
     * @param  list<ProductGroup>  $finds
     * @return array{brands: list<string>, searches: list<string>, products: array<int, array{slug: string, title: string}>, guides: list<string>}
     */
    public function allowlist(Market $market, array $finds, ?Observance $observance = null, ?int $excludeGuideId = null): array
    {
        $products = [];
        $brands = [];

        foreach ($finds as $group) {
            $products[$group->id] = ['slug' => $group->slug, 'title' => $group->title];

            if ($group->brand !== null) {
                $brands[] = $group->brand;
            }
        }

        $searches = array_values(array_unique(array_filter([
            ...($observance?->queries ?? []),
            ...array_map(fn (ProductGroup $g) => $g->category, $finds),
        ])));

        return [
            'brands' => array_values(array_unique($brands)),
            'searches' => $searches,
            'products' => $products,
            // The guides this market has published. Offered to the model for
            // the same reason the page resolves them: an edition that can send
            // a reader to the guide for what it just showed them is the link
            // between the daily half of the site and the evergreen half.
            'guides' => $this->allowlist->guideSlugs($market, $excludeGuideId),
        ];
    }

    /**
     * The curated shortlist, as the writer is shown it.
     *
     * @return list<array{id: int, title: string, note: string|null}>
     */
    public function brief(?CovePlan $plan): array
    {
        if ($plan === null) {
            return [];
        }

        return $plan->items()
            ->whereNotNull('group_id')
            ->with('group')
            ->get()
            ->filter(fn (CovePlanItem $item) => $item->group !== null)
            ->map(fn (CovePlanItem $item) => [
                'id' => (int) $item->group_id,
                'title' => (string) $item->group->title,
                'note' => $item->note,
            ])
            ->values()
            ->all();
    }

    /**
     * The themed day this plan falls on, if it is the sort of plan that has one.
     *
     * Dailies only. A seasonal part carries a `drop_date` too — a due date
     * rather than an address — and reporting the day's rotation theme against it
     * would answer a question nobody asked: that part is not competing for the
     * day and overrides nothing.
     */
    private function observanceFor(CovePlan $plan): ?Observance
    {
        if (! $plan->kind->isDated() || $plan->drop_date === null) {
            return null;
        }

        return $this->calendar->themeFor(CarbonImmutable::instance($plan->drop_date), $plan->market);
    }
}
