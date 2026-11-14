<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Services\Cove\CoveCaches;
use App\Services\Cove\EditionBuilder;
use App\Services\Settings\AutomationSettingsStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;

/**
 * Builds one market's Daily Cove edition.
 *
 * The only place the daily theme and the edition's editorial touch a model,
 * and it is a queued job under a daily cap — the AI invariant holding in the
 * feature most tempting to violate it, because "generate a theme for today"
 * reads like something a page could do on demand. It cannot: that would put
 * model latency and model cost on every visitor, seven markets over.
 */
#[Queue('editorial')]
class BuildDailyEdition implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    /**
     * One run per market and day at a time, however often it is queued (2026-09-28).
     * The schedule's `withoutOverlapping()` only guards the moment of
     * dispatch, which is over in milliseconds; this holds until the job has
     * run. Released when it finishes or fails, and after `$uniqueFor` at the
     * latest if a worker is killed mid-run.
     */
    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return $this->market->value.':'.($this->date ?? 'today');
    }

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
         * ## The topic mining is no longer in this job (2026-11-14)
         *
         * It used to run here first: count the products behind every topic
         * people searched for, then build. The edition never read the result
         * (`PlanDrafter` does), but it waited for it, and on a catalogue that
         * had grown to 1.6 million groups each count took two to five minutes.
         * be-nl and be-fr spent the job's whole fifteen minutes counting, were
         * killed by the timeout before the build started, and so left neither
         * an edition nor a reason on the plan: no Daily Cove on 12, 13 and 14
         * November. The same thing had happened in August (see
         * TopicMinerSupplyTest). The mining is `RefreshTopicQueue` now, on the
         * schedule after the morning's builds, where a slow count delays
         * nothing a reader is waiting for.
         *
         * ## Known: this gate fails closed
         *
         * `AutomationSettingsStore::stored()` catches `Throwable` and answers
         * "every switch off", which is right where it came from — during a
         * Docker build or a `migrate` against a fresh schema there is no
         * reachable database, and a provider that throws there takes out the one
         * command that would fix it.
         *
         * Here it means a database or Redis failure at exactly this moment would
         * **skip the column and log it**, where before this gate existed the job
         * would have thrown and been retried. The risk is low — everything above
         * has already queried the database, so the connection is proven — but
         * the failure is quiet, and a quietly missing Daily is one this codebase
         * has been bitten by: `/be-nl/daily` 404'd on production for weeks while
         * `/health` reported `ok`.
         *
         * If it needs tightening, the fix is to tell the two cases apart:
         * "switched off" is a legitimate empty result and "could not read the
         * switches" is not, and only the first should skip a build. See
         * docs/features/cove-automation.md.
         */
        if (! $automation->enabled('build', $this->market, CoveKind::Daily)) {
            Log::info('Daily Cove build is switched off for this market', ['market' => $this->market->value]);

            return;
        }

        $edition = $builder->build(
            $this->market,
            $this->date === null ? null : CarbonImmutable::parse($this->date),
        );

        // The Cove lists of this market (/coves, Discover, the rail) cache for
        // minutes; a new edition should not wait for them. See CoveCaches.
        CoveCaches::forgetMarket($this->market);

        Log::info('Daily Cove built', [
            'market' => $this->market->value,
            'date' => $this->date ?? 'today',
            'edition' => $edition?->id,
            'picks' => $edition?->picks()->count() ?? 0,
            // The Cove this edition points a reader at. `featured_cove_id`
            // replaced `guide_id` at the fold: a guide is a Cove now.
            'has_guide' => $edition?->featured_cove_id !== null,
        ]);
    }
}
