<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Enums\Market;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * When did this catalogue arrive, and what is genuinely newer than that?
 *
 * ## The trap
 *
 * `first_seen_at` means "we first saw it", not "it is new". Onboard an
 * advertiser and forty thousand products get that timestamp on the same
 * afternoon — products that have been on sale for years. Measured against the
 * calendar, the entire catalogue then reads as brand new for a month.
 *
 * That is not hypothetical. On staging, 38,924 of 38,924 groups had a
 * `first_seen_at` inside thirty days, so every product scored maximum novelty:
 * Trends became a random sample, and on the Deals page 22 of 24 results
 * explained themselves as "New here" instead of saying anything about price.
 *
 * ## The fix
 *
 * Measure newness against the catalogue's own history rather than against the
 * calendar. The day that contributed the most rows is the bulk import; nothing
 * first seen on or before it is new, and anything after it genuinely is.
 *
 * A young catalogue therefore reports *no* novelty at all, which is the honest
 * answer — "nothing here is new yet" beats "everything is".
 */
class CatalogueAge
{
    /** Long: the answer changes when an advertiser is onboarded, not hourly. */
    private const TTL = 3600;

    /**
     * The cutoff below which `first_seen_at` carries no information.
     *
     * Null when no single day dominates — a catalogue that grew steadily has no
     * bulk import, and every date in it is meaningful.
     */
    public function bulkImportedThrough(Market $market): ?CarbonImmutable
    {
        $value = Cache::remember(
            "bc:catalogue-age:{$market->value}",
            self::TTL,
            function () use ($market): ?string {
                $rows = DB::table('product_groups')
                    ->where('market', $market->value)
                    ->whereNotNull('first_seen_at')
                    ->selectRaw('first_seen_at::date as day, count(*) as n')
                    ->groupBy('day')
                    ->orderByDesc('n')
                    ->limit(1)
                    ->get();

                if ($rows->isEmpty()) {
                    return null;
                }

                $biggest = $rows->first();
                $total = DB::table('product_groups')->where('market', $market->value)->count();

                /*
                 * Only treat it as a bulk import if that one day really is the
                 * catalogue. A fifth of everything arriving at once is an
                 * onboarding; a busy Tuesday is not, and suppressing novelty
                 * for a busy Tuesday would throw away a real signal.
                 */
                return $total > 0 && ($biggest->n / $total) >= 0.2
                    ? (string) $biggest->day
                    : null;
            },
        );

        return $value === null ? null : CarbonImmutable::parse($value)->endOfDay();
    }

    /**
     * Novelty for one product, 0..1.
     *
     * Zero for anything that arrived with the bulk, decaying over `$window`
     * days for anything that arrived after it.
     */
    public function novelty(Market $market, ?\DateTimeInterface $firstSeen, int $window = 30): float
    {
        if ($firstSeen === null) {
            return 0.0;
        }

        $cutoff = $this->bulkImportedThrough($market);
        $seen = CarbonImmutable::instance($firstSeen);

        // Arrived with the bulk: the timestamp is an import artefact and says
        // nothing about the product.
        if ($cutoff !== null && $seen <= $cutoff) {
            return 0.0;
        }

        $age = $seen->diffInDays(CarbonImmutable::now());

        return max(0.0, min(1.0, 1.0 - ($age / max(1, $window))));
    }

    public function forget(Market $market): void
    {
        Cache::forget("bc:catalogue-age:{$market->value}");
    }
}
