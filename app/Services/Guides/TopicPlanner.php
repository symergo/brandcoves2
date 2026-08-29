<?php

declare(strict_types=1);

namespace App\Services\Guides;

use App\Enums\CoveKind;
use App\Models\CovePlan;
use App\Models\GuideTopic;
use App\Models\User;
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
    ) {}

    public function draft(GuideTopic $topic, ?User $author = null): CovePlan
    {
        if ($topic->plan_id !== null) {
            /*
             * Refused rather than made idempotent. A second plan for one topic
             * is two people writing the same guide, and quietly returning the
             * first would hide that somebody else already started.
             */
            throw new InvalidArgumentException('This topic already has a plan.');
        }

        $market = $topic->market;
        $queries = array_values(array_filter((array) $topic->member_queries, 'is_string'));

        /*
         * Seasonal topics become seasonal Coves.
         *
         * The distinction lives on the topic and nowhere else, so this is the
         * only moment it can be carried across — after this the plan is just a
         * plan, and its window is what makes it findable in season.
         */
        $kind = $topic->origin === 'seasonal' ? CoveKind::Seasonal : CoveKind::Guide;

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
        return match ($topic->origin) {
            'seasonal' => "From the seasonal calendar: {$topic->topic}, in season {$topic->season_from} to {$topic->season_to}.",
            'chart' => "From the bestseller charts: {$topic->chart_entries} charting product(s) in this category.",
            default => "From the search log: {$topic->search_volume} search(es) in 30 days, {$topic->available_products} product(s) available.",
        };
    }

    /**
     * The slug this guide will live at.
     *
     * Prefixed per language, exactly as `GuideBuilder::slug()` did, so a folded
     * guide and a newly planned one are addressed the same way. Suffixed rather
     * than allowed to collide: one slug namespace per market covers every
     * dateless kind, so a persona could already hold this one.
     */
    private function freeSlug(GuideTopic $topic): string
    {
        $base = Str::slug(
            __('site.guides.slug_prefix', [], $topic->market->language()).'-'.$topic->topic
        );

        $taken = fn (string $candidate): bool => CovePlan::query()
            ->where('market', $topic->market->value)
            ->where('slug', $candidate)
            ->exists();

        if (! $taken($base)) {
            return $base;
        }

        $n = 2;

        while ($taken($base.'-'.$n)) {
            $n++;
        }

        return $base.'-'.$n;
    }
}
