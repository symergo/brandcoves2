<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Services\Cove\EditionBuilder;
use App\Services\Guides\SeasonalTopics;
use App\Services\Guides\TopicMiner;
use App\Services\Settings\AutomationSettingsStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Builds one market's Daily Cove edition.
 *
 * The only place the daily theme and the guide's editorial copy touch a model,
 * and it is a queued job under a daily cap — the AI invariant holding in the
 * feature most tempting to violate it, because "generate a theme for today"
 * reads like something a page could do on demand. It cannot: that would put
 * model latency and model cost on every visitor, seven markets over.
 */
class BuildDailyEdition implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    /**
     * @param  string|null  $date  `Y-m-d`, or null for today.
     *
     * A plain string rather than a Carbon instance: this is serialised into
     * Redis and back, and a date that survives the round trip unchanged is
     * worth more here than a typed constructor. Null is today, which is what
     * the nightly schedule wants and what every existing caller passed.
     */
    public function __construct(public Market $market, public ?string $date = null) {}

    public function handle(
        TopicMiner $miner,
        SeasonalTopics $seasonal,
        EditionBuilder $builder,
        AutomationSettingsStore $automation,
    ): void {
        /*
         * The Daily's `build` switch gates this job.
         *
         * Gated rather than absorbed into the automation walk, and the reason is
         * the clock: this runs at 06:00 for a 09:00 drop, and those three hours
         * are deliberate — enough for a retry to land or for somebody to notice
         * before the page is due. A walk that also approved and curated could not
         * hold that window without running the whole pipeline at six in the
         * morning.
         *
         * The switch ships **on** for every market, which is what this job has
         * always done. Turning it off is how a market stops publishing a column,
         * and it is now a decision somebody can make on a screen rather than a
         * deploy.
         *
         * The mining below is deliberately outside the gate: it is a fact about
         * the day rather than about the column, and the topic queue should keep
         * advancing in a market that has paused its Daily.
         */
        // Mine first: the edition asks for the ripest topic, and yesterday's
        // searches are what ripen one.
        $candidates = $miner->mine($this->market);

        // Then the calendar, which knows about seasons the log cannot see yet —
        // barbecue demand peaks in June and a log-only queue would commission the
        // barbecue Cove in July.
        $inSeason = $seasonal->seed($this->market);

        if (! $automation->enabled('build', $this->market, CoveKind::Daily)) {
            Log::info('Daily Cove build is switched off for this market', ['market' => $this->market->value]);

            return;
        }

        $edition = $builder->build(
            $this->market,
            $this->date === null ? null : CarbonImmutable::parse($this->date),
        );

        Log::info('Daily Cove built', [
            'market' => $this->market->value,
            'date' => $this->date ?? 'today',
            'topic_candidates' => $candidates,
            'topics_in_season' => $inSeason,
            'edition' => $edition?->id,
            'picks' => $edition?->picks()->count() ?? 0,
            // The Cove this edition points a reader at. `featured_cove_id`
            // replaced `guide_id` at the fold: a guide is a Cove now.
            'has_guide' => $edition?->featured_cove_id !== null,
        ]);
    }
}
