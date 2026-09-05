<?php

declare(strict_types=1);

namespace App\Services\Guides;

use App\Enums\CoveKind;
use App\Models\CovePlan;
use App\Models\GuideTopic;
use App\Models\User;
use App\Services\Cove\PlanSlugs;
use App\Services\Cove\SeasonalSeries;
use App\Services\Cove\Selectors\LadderSelector;
use App\Services\Curation\PlanCurator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Turns a mined or seasonal topic into a draft plan for a person to curate.
 *
 * The topic queue used to publish. Queue a topic and, one night, `GuideBuilder`
 * chose its own products, wrote about them and put the page live — so the only
 * editorial control was rewriting the sentences afterwards, and the shortlist
 * that made the page worth reading was nobody's decision.
 *
 * It is now an idea feed, and this is the join. The topic supplies the three
 * things only it knows — the phrase people actually typed, the season it belongs
 * to, and the measured search volume — and a curator supplies the rest on the
 * curation screen.
 *
 * The plan is a **draft**, always. The whole point of drafting is that somebody
 * reads it before it publishes, and a queue that produced approved plans would
 * be the old pipeline again with an extra table in it.
 */
class TopicPlanner
{
    public function __construct(
        private readonly LadderSelector $ladder,
        private readonly PlanCurator $curator,
        private readonly PlanSlugs $slugs,
        private readonly SeasonalSeries $series,
    ) {}

    /**
     * The first plan this topic produced.
     *
     * Kept as the entry point every screen already calls, and it is still one
     * plan for a mined topic. A seasonal topic now produces several — see
     * {@see draftAll()} — and this hands back the one the topic itself points
     * at, which is what a caller that redirects somebody to "its plan" wants.
     */
    public function draft(GuideTopic $topic, ?User $author = null): CovePlan
    {
        return $this->draftAll($topic, $author)[0];
    }

    /**
     * Every plan this topic produces, in order.
     *
     * A mined topic is one buying guide, exactly as before. A **seasonal** topic
     * is a series: a season is months long and names several subjects, so it is
     * laid out as parts with a date each, inside its own window. See
     * {@see SeasonalSeries}, which is where that decision lives — this method's
     * job is only to know which topics go there.
     *
     * @return non-empty-list<CovePlan>
     */
    public function draftAll(GuideTopic $topic, ?User $author = null): array
    {
        if ($topic->plan_id !== null) {
            /*
             * Refused rather than made idempotent. A second plan for one topic
             * is two people writing the same guide, and quietly returning the
             * first would hide that somebody else already started.
             */
            throw new InvalidArgumentException('This topic already has a plan.');
        }

        if ($topic->origin === 'seasonal') {
            $parts = $this->series->lay($topic, $author);

            if ($parts === []) {
                /*
                 * Thrown rather than returning an empty list, because every
                 * caller of this is a person or an agent asking for a plan and
                 * has to be told why there is not one. The season is left
                 * un-queued on purpose: a category that is thin in April may
                 * have an advertiser in May, and this is the same "parked, not
                 * banned" rule the topic queue already runs on.
                 */
                throw new InvalidArgumentException(
                    "The catalogue in {$topic->market->value} cannot fill a single part of \"{$topic->topic}\" yet. "
                    .'The season stays in the queue and will be offered again once more of it is in stock.'
                );
            }

            return $parts;
        }

        return [$this->one($topic, $author)];
    }

    /** One buying guide from one mined topic — the original path, unchanged. */
    private function one(GuideTopic $topic, ?User $author): CovePlan
    {
        $market = $topic->market;
        $queries = array_values(array_filter((array) $topic->member_queries, 'is_string'));

        /*
         * A buying guide, always. Seasonal topics never reach here — they are
         * laid out as a series by `SeasonalSeries`, which is the only place that
         * knows a season has parts and dates.
         *
         * `season_from` and `season_to` are still copied below rather than
         * dropped: they are null on every topic that reaches here today, and a
         * copy that is always null costs nothing where a deletion would have to
         * be noticed and undone the day a windowed non-seasonal topic exists.
         */
        $kind = CoveKind::Guide;

        $plan = DB::transaction(function () use ($topic, $market, $queries, $kind, $author): CovePlan {
            $plan = CovePlan::create([
                'market' => $market->value,
                'kind' => $kind->value,
                // The topic word is the headline until a person improves it. It
                // is at least what people searched for, which is more than a
                // generated title can claim.
                'title' => Str::ucfirst($topic->topic),
                'slug' => $this->freeSlug($topic),
                // The phrase the page is written to answer — the reason this
                // topic exists at all.
                'focus_keyphrase' => $topic->topic,
                'queries' => $queries,
                'season_from' => $topic->season_from,
                'season_to' => $topic->season_to,
                'status' => 'draft',
                'created_by' => $author?->id,
                'note' => $this->provenance($topic),
            ]);

            $topic->forceFill(['plan_id' => $plan->id, 'status' => 'queued'])->save();

            return $plan;
        });

        /*
         * Pre-filled with what the builder would have chosen.
         *
         * A plan that opens empty asks an editor to invent seven products from
         * nothing, which is the blank page this screen exists to remove. These
         * are suggestions to react to, and they carry no note — the note is the
         * reason a *person* chose something, and the machine has none to give.
         */
        $this->curator->prefill(
            $plan,
            $this->ladder->select($plan, collect(), $kind->targetItems()),
        );

        return $plan->refresh();
    }

    /**
     * Why this plan exists, in the words of the thing that suggested it.
     *
     * Written into the note because the topic's evidence is the most persuasive
     * argument for writing the page, and it is invisible from the plan otherwise.
     */
    private function provenance(GuideTopic $topic): string
    {
        // No seasonal arm: a seasonal topic is laid out by `SeasonalSeries`,
        // which writes a note naming the part and its subject as well as the
        // window. An arm here would be dead and would read as the live one.
        return match ($topic->origin) {
            'chart' => "From the bestseller charts: {$topic->chart_entries} charting product(s) in this category.",
            default => "From the search log: {$topic->search_volume} search(es) in 30 days, {$topic->available_products} product(s) available.",
        };
    }

    /**
     * The slug this guide will live at.
     *
     * Prefixed per language, exactly as `GuideBuilder::slug()` did, so a folded
     * guide and a newly planned one are addressed the same way. The collision
     * handling belongs to {@see PlanSlugs}: one slug namespace per market covers
     * every dateless kind, so a persona could already hold this one.
     */
    private function freeSlug(GuideTopic $topic): string
    {
        return $this->slugs->free(
            $topic->market,
            __('site.guides.slug_prefix', [], $topic->market->language()).'-'.$topic->topic,
        );
    }
}
