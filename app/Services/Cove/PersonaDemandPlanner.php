<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\CoveKind;
use App\Enums\CoveScene;
use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Models\CovePlan;
use App\Models\GiftLanding;
use App\Services\Curation\PlanCurator;
use App\Services\Gift\GiftLandingCopy;
use App\Services\Gift\GiftSearchDemand;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Gift personas drafted from what people search for (the owner's request 6,
 * 2026-09-26).
 *
 * Nightly, per market: the gift searches of the last quarter, read as briefs
 * ("sister + yoga", "dad + cooking", "someone who has everything"), are
 * counted ({@see GiftSearchDemand}). A reading searched often enough, on
 * enough different days, that no persona and no landing page answers yet,
 * and that the catalogue can fill with eight products, becomes a **draft**
 * persona plan: a brief, a placeholder title from a template, and the
 * products the builder would choose, for a person to rename, curate and
 * approve in the Cove planner.
 *
 * Never more than a draft. Nothing here approves, builds or publishes, and
 * nothing calls a model: the title is a template, the products are the
 * suggestion engine's (retrieval and arithmetic). It runs in a queued job,
 * and works the same with `AI_ENABLED=false`.
 *
 * See docs/features/persona-demand.md.
 */
class PersonaDemandPlanner
{
    /** How a drafted persona says where it came from, on its note. */
    public const MARK = 'Drafted from search demand';

    public function __construct(
        private readonly GiftSearchDemand $demand,
        private readonly SuggestionEngine $engine,
        private readonly EditionBuilder $builder,
        private readonly PlanCurator $curator,
        private readonly PlanSlugs $slugs,
    ) {}

    /**
     * @return array{drafted: list<array{title: string, slug: string, brief: array<string, mixed>, searches: int, days: int}>, skipped: array<string, int>}
     */
    public function plan(Market $market, bool $dryRun = false): array
    {
        $config = (array) config('giftcoves.persona_demand');
        $skipped = ['below_bar' => 0, 'landing_page' => 0, 'persona' => 0, 'too_few_products' => 0, 'over_cap' => 0];
        $drafted = [];
        $plans = [];

        $readings = array_values(array_filter(
            $this->demand->readings($market, (int) $config['window_days'], (int) $config['log_rows']),
            fn (array $r) => $this->understood($r),
        ));

        // The most searched first, so the nightly cap keeps the strongest.
        usort($readings, fn (array $a, array $b) => [$b['searches'], $b['days']] <=> [$a['searches'], $a['days']]);

        $personas = $this->personas($market);

        foreach ($readings as $reading) {
            if ($reading['searches'] < (int) $config['min_searches'] || $reading['days'] < (int) $config['min_days']) {
                $skipped['below_bar']++;

                continue;
            }

            if ($this->hasLandingPage($market, $reading)) {
                $skipped['landing_page']++;

                continue;
            }

            if ($personas->contains(fn (CovePlan $plan) => $this->answers($plan, $reading, $market))) {
                $skipped['persona']++;

                continue;
            }

            if (count($drafted) >= (int) $config['max_drafts']) {
                $skipped['over_cap']++;

                continue;
            }

            $brief = $this->brief($market, $reading);

            if ($this->fitting($brief) < (int) $config['min_products']) {
                $skipped['too_few_products']++;

                continue;
            }

            $title = $this->title($market, $reading);
            $slug = $this->slugs->free($market, $title);

            $drafted[] = [
                'title' => $title,
                'slug' => $slug,
                'brief' => $brief->toArray(),
                'searches' => $reading['searches'],
                'days' => $reading['days'],
            ];

            if ($dryRun) {
                continue;
            }

            $plan = CovePlan::create([
                'market' => $market->value,
                'kind' => CoveKind::Persona->value,
                'status' => 'draft',
                'title' => $title,
                'slug' => $slug,
                'brief' => $brief->toArray(),
                'scene' => $this->scene($reading)?->value,
                'note' => self::MARK.' ('.$this->describe($reading).'): searched '.$reading['searches'].' times on '
                    .$reading['days'].' days in the last '.$config['window_days'].' days, and no persona or landing '
                    .'page answered it. The title is a placeholder from a template: rename it after somebody a reader '
                    .'would recognise, check the products, then approve.',
            ]);

            $this->prefill($plan, $plans, (int) $config['min_products']);

            $plans[] = $plan;
            // The next reading this run must see this one as taken.
            $personas->push($plan);
        }

        return ['drafted' => $drafted, 'skipped' => $skipped];
    }

    /**
     * A reading the gift vocabulary understands, and that a persona can be
     * about: an interest, or "has everything". Values outside the enums (a
     * word list renamed since the count) are dropped rather than guessed.
     *
     * @param  array{relationship: string|null, interest: string|null, hasEverything: bool, searches: int, days: int}  $r
     */
    private function understood(array $r): bool
    {
        if ($r['relationship'] !== null && RecipientType::tryFrom($r['relationship']) === null) {
            return false;
        }

        if ($r['interest'] === null) {
            return $r['hasEverything'];
        }

        return Interest::tryFrom($r['interest']) !== null
            && ! in_array($r['interest'], (array) config('giftcoves.gift_landings.excluded_interests', []), true);
    }

    /**
     * "Dad + cooking" has its landing page already. A has-everything reading
     * never does: a landing page is who and what they love, nothing else.
     *
     * @param  array{relationship: string|null, interest: string|null, hasEverything: bool}  $r
     */
    private function hasLandingPage(Market $market, array $r): bool
    {
        if ($r['hasEverything'] || $r['relationship'] === null || $r['interest'] === null) {
            return false;
        }

        return GiftLanding::lookup($market, RecipientType::from($r['relationship']), Interest::from($r['interest'])) !== null;
    }

    /**
     * Every persona plan in the market, whatever its status. A rejected
     * draft still counts: rejecting it is what stops it coming back.
     *
     * @return Collection<int, CovePlan>
     */
    private function personas(Market $market): Collection
    {
        return CovePlan::query()
            ->where('market', $market->value)
            ->where('kind', CoveKind::Persona->value)
            ->get(['id', 'market', 'kind', 'slug', 'title', 'scene', 'brief']);
    }

    /**
     * Does this persona already answer the reading?
     *
     * With a brief, by the brief: the interest is in it, and it is about the
     * same person or about nobody in particular ("the yogi" answers "sister +
     * yoga"). Without one (the hand-written personas, chosen by search terms),
     * by what it is about: its drawing or its title names the interest.
     *
     * @param  array{relationship: string|null, interest: string|null, hasEverything: bool}  $r
     */
    private function answers(CovePlan $plan, array $r, Market $market): bool
    {
        $brief = is_array($plan->brief) ? $plan->brief : [];

        if ($brief !== []) {
            $sameOrAnyone = ($brief['relationship'] ?? null) === null || $brief['relationship'] === $r['relationship'];

            if ($r['hasEverything']) {
                return $sameOrAnyone && (bool) ($brief['hasEverything'] ?? false);
            }

            return $sameOrAnyone && in_array($r['interest'], (array) ($brief['interests'] ?? []), true);
        }

        if ($r['hasEverything']) {
            return $plan->scene === CoveScene::HasEverything;
        }

        if ($plan->scene !== null && $plan->scene->value === $r['interest']) {
            return true;
        }

        $title = mb_strtolower((string) $plan->title);

        foreach ($this->namesFor((string) $r['interest'], $market) as $name) {
            if (str_contains($title, $name) || str_contains((string) $plan->slug, Str::slug($name))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The words a persona's title would use for an interest: its label, and
     * the search box's own synonyms ("koffie", "kok", "lezer"), so "De
     * thuiskok" is seen to be about cooking. Three letters or more.
     *
     * @return list<string>
     */
    private function namesFor(string $interest, Market $market): array
    {
        $language = $market->language();
        $synonyms = (array) (trans('intent.interests', [], $language)[$interest] ?? []);

        $names = array_map(
            fn ($name) => mb_strtolower(trim((string) $name)),
            [(string) __('site.gift.interests.'.$interest, [], $language), $interest, ...$synonyms],
        );

        return array_values(array_unique(array_filter($names, fn (string $n) => mb_strlen($n) >= 3)));
    }

    /** @param  array{relationship: string|null, interest: string|null, hasEverything: bool}  $r */
    private function brief(Market $market, array $r): TasteBrief
    {
        return new TasteBrief(
            market: $market,
            interests: $r['interest'] === null ? [] : [$r['interest']],
            relationship: $r['relationship'],
            limit: max(16, 2 * (int) config('giftcoves.persona_demand.min_products')),
            hasEverything: $r['hasEverything'],
        );
    }

    /**
     * How many distinct products answer the brief well enough to be on the
     * page: the ones that fit its interest by the engine's own verdict, or
     * that get used up or done for a has-everything brief. "The engine
     * returned something" is not enough; with nothing matching it falls back
     * to a budget browse (the same rule as the landing pages).
     */
    private function fitting(TasteBrief $brief): int
    {
        $ids = [];

        foreach ($this->engine->suggest($brief) as $suggestion) {
            $fits = $brief->hasEverything
                ? $suggestion->consumable || ($brief->interests !== [] && $suggestion->matchedInterests !== [])
                : $suggestion->matchedInterests !== [];

            if ($fits) {
                $ids[$suggestion->group->id] = true;
            }
        }

        return count($ids);
    }

    /**
     * A placeholder from a template, never from a model: "Papa die van koken
     * houdt", "Yoga — cadeau-ideeën", "Wie alles al heeft". The note asks
     * for a better one before approval.
     *
     * @param  array{relationship: string|null, interest: string|null, hasEverything: bool}  $r
     */
    private function title(Market $market, array $r): string
    {
        $language = $market->language();

        if ($r['hasEverything']) {
            if ($r['relationship'] === null) {
                return (string) __('site.gift_ideas.has_everything_title', [], $language);
            }

            $who = (new GiftLandingCopy($market, RecipientType::from($r['relationship'])))->recipientName();

            return (string) __('site.gift_ideas.has_everything_title_for', ['recipient' => $who], $language);
        }

        $interest = Interest::from((string) $r['interest']);

        if ($r['relationship'] !== null) {
            return Str::ucfirst((new GiftLandingCopy($market, RecipientType::from($r['relationship']), $interest))->bare());
        }

        return (string) __('site.gift_ideas.draft_title', [
            'interest' => __('site.gift.interests.'.$interest->value, [], $language),
        ], $language);
    }

    /** The drawing, when one fits the interest; null draws a figure. */
    private function scene(array $r): ?CoveScene
    {
        if ($r['hasEverything']) {
            return CoveScene::HasEverything;
        }

        $scene = CoveScene::tryFrom((string) $r['interest']);

        return $scene !== null && in_array($scene, CoveScene::forKind(CoveKind::Persona), true) ? $scene : null;
    }

    /** @param  array{relationship: string|null, interest: string|null, hasEverything: bool}  $r */
    private function describe(array $r): string
    {
        return implode(' + ', array_filter([
            $r['relationship'],
            $r['interest'],
            $r['hasEverything'] ? 'has everything' : null,
        ]));
    }

    /**
     * The builder's own choice for the brief, so the draft opens with
     * products to react to. Excludes what this run's earlier drafts took,
     * or two drafts about similar people would open on the same shelf.
     *
     * @param  list<CovePlan>  $earlier
     */
    private function prefill(CovePlan $plan, array $earlier, int $count): void
    {
        $taken = [];

        foreach ($earlier as $other) {
            foreach ($other->items as $item) {
                if ($item->group_id !== null) {
                    $taken[] = (int) $item->group_id;
                }
            }
        }

        $this->curator->prefill($plan, $this->builder->candidates(
            $plan,
            max($count, $plan->kind->targetItems()),
            array_values(array_unique($taken)),
        ));

        $plan->load('items');
    }
}
