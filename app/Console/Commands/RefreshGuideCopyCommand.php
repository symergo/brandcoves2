<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiUsage;
use App\Models\Guide;
use App\Services\Guides\GuideBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Re-write the prose of guides that never got any, and of guides whose prose has
 * aged.
 *
 * ## Why this exists
 *
 * Nothing revisited a published guide. A guide built in a window where the model
 * was unreachable kept its template copy permanently, and there was no way to
 * tell from the outside: the page renders, it just has no editorial in it.
 *
 * That window was real and it was not short. `AiClient` read the answer out of
 * `content[0]`, which is a `thinking` block on any prompt long enough to warrant
 * one, so every guide generated during it fell back to the template while the
 * usage table showed successful calls and zero errors.
 *
 * The monthly freshness re-check the build plan calls for is the same operation
 * on a different trigger, so it is the same command.
 *
 * ## Order of work
 *
 * Guides with no AI copy at all go first. A guide with a stale but real
 * paragraph is in far better shape than one with none, and the daily cap means
 * a run usually cannot have both.
 */
class RefreshGuideCopyCommand extends Command
{
    protected $signature = 'bc:refresh-guide-copy
                            {--stale=60 : also refresh guides not checked in this many days}
                            {--limit=25 : most guides to touch in one run}
                            {--market= : restrict to one market}';

    protected $description = 'Re-attempt editorial copy for guides that have none, then for stale ones';

    private const FEATURE = 'guide_copy';

    public function handle(GuideBuilder $builder): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $stale = max(1, (int) $this->option('stale'));

        $guides = $this->candidates($stale, $limit);

        if ($guides->isEmpty()) {
            $this->info('Nothing to refresh.');

            return self::SUCCESS;
        }

        $refreshed = 0;
        $skipped = 0;

        foreach ($guides as $guide) {
            /*
             * Checked per guide rather than once up front. The cap counts calls,
             * and other features share the day; carrying on past it would make a
             * failed call per remaining guide, each one logged as if the model
             * had let us down.
             */
            if (! AiUsage::withinCap(self::FEATURE)) {
                $this->warn('Daily cap for ['.self::FEATURE."] reached, stopping. {$refreshed} refreshed, ".
                    ($guides->count() - $refreshed - $skipped).' left for the next run.');
                break;
            }

            if ($builder->refreshCopy($guide)) {
                $refreshed++;
                $this->line("  rewrote {$guide->market->value}/{$guide->slug}");

                continue;
            }

            $skipped++;
            $this->line("  left alone {$guide->market->value}/{$guide->slug}");
        }

        $this->info("Refreshed {$refreshed}, left alone {$skipped}.");

        return self::SUCCESS;
    }

    /** @return Collection<int, Guide> */
    private function candidates(int $stale, int $limit)
    {
        $market = $this->option('market');

        $base = fn () => Guide::query()
            ->published()
            ->when(is_string($market) && $market !== '', fn ($q) => $q->where('market', $market));

        // No AI copy: `body_md` is only ever written from a model answer, so a
        // null one is a guide that has never had a word generated for it.
        $missing = $base()->whereNull('body_md')->orderBy('id')->limit($limit)->get();

        if ($missing->count() >= $limit) {
            return $missing;
        }

        $aged = $base()
            ->whereNotNull('body_md')
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subDays($stale)))
            ->whereNotIn('id', $missing->pluck('id'))
            ->orderBy('last_checked_at')
            ->limit($limit - $missing->count())
            ->get();

        return $missing->concat($aged);
    }
}
