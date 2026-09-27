<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AlertState;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Jobs\Concerns\RunsOneAtATime;
use App\Models\PriceAlert;
use App\Models\Product;
use App\Models\RestockAlert;
use App\Models\WishlistItem;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Ingestion\OfferUpserter;
use App\Services\Ingestion\ProductGrouper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-checks the products people actually care about, then has the alerts fired.
 *
 * Feed ingestion runs twice a day over the whole catalogue; this runs over the
 * tiny subset that is on someone's list or under an alert, and asks the live
 * sources (bol) for today's price on it.
 *
 * Since 2026-09-28:
 *
 * - it is the last step of the catalogue run (App\Services\Ingestion\
 *   CatalogueRun), after every market is grouped, rather than at 05:20;
 * - it stops fetching after TIME_BUDGET seconds, so a slow or throttled
 *   source cannot keep a batch worker for the job's whole timeout;
 * - it recomputes the groups whose offers it changed, so a watched product's
 *   cheapest price and stock are today's at once, not after the next grouping
 *   twelve hours later (a restock alert reads the group's `in_stock`);
 * - the alerts are fired by their own job, FireWatchAlerts, on the `mail`
 *   queue.
 */
#[Queue('batch')]
class RefreshWishlistedProducts implements ShouldQueue
{
    use Queueable, RunsOneAtATime;

    public int $timeout = 600;

    /**
     * How many live offers one run may re-fetch.
     *
     * Every fetch is a request against a rate-limited API. Watched products
     * are a small set — somebody had to press a button for each — so this is
     * a ceiling against a runaway rather than a budget the job expects to
     * spend. The rest wait for the next run, twice a day.
     */
    private const REFRESH_CAP = 500;

    /**
     * Seconds of fetching per run. Half the timeout, so what was fetched is
     * always written and recomputed before the worker would kill the job.
     */
    private const TIME_BUDGET = 300;

    protected function overlapKey(): string
    {
        return 'all';
    }

    public function handle(?ConnectorRegistry $registry = null, ?OfferUpserter $upserter = null, ?ProductGrouper $grouper = null): void
    {
        // Injected by the queue; resolved here for the tests that run the job
        // by hand, as they did before it had dependencies.
        $registry ??= app(ConnectorRegistry::class);
        $upserter ??= app(OfferUpserter::class);
        $grouper ??= app(ProductGrouper::class);

        [$refreshed, $touched] = $this->refreshLiveOffers($registry, $upserter);

        foreach ($touched as $market => $groupIds) {
            $grouper->recomputeGroups(Market::from($market), $groupIds);
        }

        Log::info('Wishlist refresh complete', [
            'refreshed' => $refreshed,
            'groups_recomputed' => array_sum(array_map('count', $touched)),
        ]);

        FireWatchAlerts::dispatch();
    }

    /**
     * Ask the live sources for today's price on every watched product.
     *
     * Until 2026-09-06 nothing did. `LiveConnector::fetchById()` was declared
     * "for wishlist and daily-pick re-checks", implemented by every live
     * connector, and called by nothing — so a product whose only offers came
     * from bol had a price that changed only when somebody happened to search
     * for it, and a watched item could sit at an unchanging number forever.
     *
     * Feed-sourced offers are refreshed by ingestion and are left alone here.
     * A source that is cooling down is skipped this run rather than waited
     * for; a fetch that answers nothing leaves the row as it was, because
     * "the API did not answer" and "the product is gone" are different facts
     * and only ingestion's stale sweep is entitled to the second.
     *
     * Each source is asked through `LiveConnector::refresh()`, which is handed
     * both keys the row carries — the external id and the barcode — and picks
     * the one it can answer for. Until 2026-09-18 this called `fetchById()`
     * with the external id, and for bol that could never work: bol's product
     * endpoint takes an EAN and answers 400 for the `bolProductId` we store, so
     * every bol offer here was skipped, silently, and a watched bol product's
     * price never moved. The job cannot make that choice itself — which key a
     * source accepts is a fact about the source.
     *
     * @return array{0: int, 1: array<string, list<int>>} rows written, and the
     *                                                    groups they belong to per market
     */
    private function refreshLiveOffers(ConnectorRegistry $registry, OfferUpserter $upserter): array
    {
        $groupIds = PriceAlert::query()
            ->whereIn('state', [AlertState::Active->value, AlertState::Triggered->value])
            ->pluck('group_id')
            ->merge(RestockAlert::query()
                ->whereIn('state', [AlertState::Active->value, AlertState::Triggered->value])
                ->pluck('group_id'))
            /*
             * And everything on a list whose owner watches its prices: the
             * digest that runs after this job compares against today's price,
             * and for a bol-only product today's price is whatever this fetch
             * says. See App\Services\Alerts\ListPriceWatch.
             */
            ->merge(WishlistItem::query()
                ->whereNotNull('group_id')
                ->whereNotNull('accepted_at')
                ->whereHas('wishlist', fn ($q) => $q->whereNotNull('price_watch_percent'))
                ->pluck('group_id'))
            ->unique()
            ->values();

        if ($groupIds->isEmpty()) {
            return [0, []];
        }

        $liveSources = array_map(fn (Source $s) => $s->value, $registry->liveSources());

        if ($liveSources === []) {
            return [0, []];
        }

        $fetched = [];
        $asked = 0;
        $written = 0;
        $touched = [];
        $deadline = microtime(true) + self::TIME_BUDGET;

        Product::query()
            ->whereIn('group_id', $groupIds)
            ->whereIn('source', $liveSources)
            ->where('status', ProductStatus::Active->value)
            // `ean` rides along because it is the only key bol can be asked
            // with; every other column here was already needed.
            ->select(['id', 'source', 'external_id', 'ean', 'market', 'group_id'])
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($registry, $upserter, &$fetched, &$asked, &$written, &$touched, $deadline): bool {
                $stop = false;

                foreach ($products as $product) {
                    if ($asked >= self::REFRESH_CAP || microtime(true) >= $deadline) {
                        $stop = true;

                        break;
                    }

                    $connector = $registry->live($product->source);

                    if ($connector === null || $connector->isCoolingDown() || ! $connector->supports($product->market)) {
                        continue;
                    }

                    $asked++;

                    try {
                        $offer = $connector->refresh($product->external_id, $product->ean, $product->market);
                    } catch (Throwable $e) {
                        report($e);

                        continue;
                    }

                    if ($offer !== null) {
                        $fetched[] = $offer;
                        $touched[$product->market->value][] = (int) $product->group_id;
                    }
                }

                // Written per chunk, and also when the budget ran out, so
                // nothing already fetched is thrown away.
                if ($fetched !== []) {
                    $written += (int) ($upserter->upsert($fetched)['written'] ?? 0);
                    $fetched = [];
                }

                return ! $stop;
            });

        return [$written, array_map(fn (array $ids) => array_values(array_unique($ids)), $touched)];
    }
}
