<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\Market;
use App\Jobs\ClassifyGiftability;
use App\Jobs\ContinueCatalogueRun;
use App\Jobs\FindMatchCandidates;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Jobs\LinkBarcodeItems;
use App\Jobs\PlanGiftLandingPages;
use App\Jobs\RefreshBrandStats;
use App\Jobs\RefreshWishlistedProducts;
use App\Models\Feed;
use Illuminate\Support\Facades\Bus;

/**
 * The catalogue's twice-daily run: each step starts when the one before it is
 * done, market by market, instead of at a fixed time (2026-09-28).
 *
 * Until then the schedule guessed: ingest at 04:10, group at 05:00, classify at
 * 05:10, brand statistics and barcode links at 05:30, match review and landing
 * pages at 05:40, and the same again from 16:10. A large feed that took longer
 * than fifty minutes was grouped half-loaded; a small one left the database
 * idle for forty minutes; and on a busy night every market's grouping ran at
 * the same moment. Now, per market:
 *
 *   ingest every enabled feed of the market (a batch; one feed failing does
 *   not stop the others or the steps after it)
 *   → group → classify → brand statistics → match candidates
 *   → gift landing pages (morning run, published markets only)
 *   → the next market
 *
 * and after the last market, the barcode links and the watched products'
 * refresh, which read every market at once.
 *
 * One market at a time, so at most one market's catalogue pass holds the
 * database; the `batch` queue allows two workers, which lets two feeds of one
 * market download at once.
 *
 * A step that fails for good (after its own retries) stops only its market:
 * the chain's `catch` moves on to the next one, so be-nl failing to group does
 * not leave nl-nl ungrouped. Each step still guards itself against running
 * twice at once (App\Jobs\Concerns\RunsOneAtATime); none of them may be
 * `ShouldBeUnique`, because Laravel drops a unique job refused inside a chain
 * together with everything after it.
 */
final class CatalogueRun
{
    /**
     * Start (or continue) a run over these markets, first one first.
     *
     * @param  list<Market>  $markets
     * @param  bool  $morning  the morning run also plans the gift landing pages
     */
    public static function start(array $markets, bool $morning): void
    {
        if ($markets === []) {
            self::finish();

            return;
        }

        $market = array_shift($markets);

        Bus::chain([...self::stepsFor($market, $morning), new ContinueCatalogueRun($markets, $morning)])
            ->onQueue('batch')
            ->catch(function () use ($markets, $morning): void {
                // A step of this market failed for good; the others still run.
                ContinueCatalogueRun::dispatch($markets, $morning);
            })
            ->dispatch();
    }

    /**
     * One market's steps, in order.
     *
     * @return list<object>
     */
    public static function stepsFor(Market $market, bool $morning): array
    {
        $feeds = Feed::query()->enabled()->where('market', $market->value)->orderBy('id')->pluck('id');

        $steps = [];

        if ($feeds->isNotEmpty()) {
            $steps[] = Bus::batch($feeds->map(fn (int $id) => new IngestFeed($id))->all())
                ->name("Ingest {$market->value}")
                ->onQueue('batch')
                // A feed that fails (after its own retry) must not cancel its
                // siblings or the grouping of what did arrive.
                ->allowFailures();
        }

        $steps[] = new GroupProducts($market);
        $steps[] = new ClassifyGiftability($market);
        $steps[] = new RefreshBrandStats($market);
        $steps[] = new FindMatchCandidates($market);

        // Once a day is enough for which landing pages exist, and only a
        // market with visitors has any. It ran at 05:40 before.
        if ($morning && $market->isPublished()) {
            $steps[] = new PlanGiftLandingPages($market);
        }

        return $steps;
    }

    /** After every market: the two steps that read all of them. */
    private static function finish(): void
    {
        Bus::chain([new LinkBarcodeItems, new RefreshWishlistedProducts])
            ->onQueue('batch')
            ->dispatch();
    }
}
