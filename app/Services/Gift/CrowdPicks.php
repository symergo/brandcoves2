<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "People shopping for someone like this also picked it."
 *
 * The owner's request of 2026-09-26: use the gifts people put on their lists
 * as suggestions for other people. A product counts for a kind of person
 * (a father; a father who likes cooking) once at least
 * `giftcoves.list_signals.min_owners` different people keep it on a list for
 * that kind of person. The counting is CountListSignals's, nightly, into
 * `crowd_picks`; this class only looks it up for a brief, within the brief's
 * market (invariant 2). The rules are in {@see CrowdPickMatcher}; the page is
 * docs/features/crowd-picks.md.
 *
 * ## Nothing per request but one indexed read, usually cached
 *
 * A brief's contexts are looked up by equality on the table's primary key,
 * and the rows are cached until the next count (the count bumps a version, so
 * a new night's numbers are never hidden behind yesterday's cache). An empty
 * table costs one index probe, then a cache hit.
 *
 * ## Thin data is no data
 *
 * Production held about 13 lists when this was built, so on day one nothing
 * reaches five people and every lookup returns nothing: no boost, no label,
 * no error. That is the design, not a gap to paper over with a lower
 * threshold.
 */
class CrowdPicks
{
    /** Rows read per brief: enough for any board, bounded for a popular context. */
    private const MAX_ROWS = 400;

    private const VERSION_KEY = 'crowd_picks.version';

    /** A day and a bit: the count runs nightly and bumps the version anyway. */
    private const TTL_SECONDS = 26 * 3600;

    public function __construct(private readonly CrowdPickMatcher $matcher) {}

    /**
     * Products people shopping for someone like the brief's person picked.
     *
     * @return array<int, CrowdPick> group id => pick, strongest first
     */
    public function forBrief(TasteBrief $brief): array
    {
        $contexts = $this->matcher->contexts($brief);

        if ($contexts === []) {
            return [];
        }

        $rows = Cache::remember(
            $this->key($brief->market, 'brief:'.md5(implode('|', $contexts))),
            self::TTL_SECONDS,
            fn () => DB::table('crowd_picks')
                ->where('market', $brief->market->value)
                ->whereIn('context', $contexts)
                ->orderByDesc('owners')
                ->limit(self::MAX_ROWS)
                ->get(['group_id', 'context', 'owners'])
                ->map(fn ($row) => ['group_id' => (int) $row->group_id, 'context' => (string) $row->context, 'owners' => (int) $row->owners])
                ->all(),
        );

        return $this->matcher->score($rows, $this->minOwners());
    }

    /**
     * Products on the lists of enough people for any kind of person in this
     * market: proven gifts, with no brief. For This or that, which draws its
     * pool before it knows anybody's taste.
     *
     * @return list<int>
     */
    public function provenGifts(Market $market, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return Cache::remember(
            $this->key($market, 'proven:'.$limit),
            self::TTL_SECONDS,
            fn () => DB::table('crowd_picks')
                ->where('market', $market->value)
                ->where('owners', '>=', $this->minOwners())
                ->groupBy('group_id')
                ->orderByRaw('max(owners) desc')
                ->limit($limit)
                ->pluck('group_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        );
    }

    /** Called by the nightly count once it has rewritten the table. */
    public static function forgetCached(): void
    {
        Cache::forever(self::VERSION_KEY, bin2hex(random_bytes(4)));
    }

    /**
     * The privacy guarantee: nothing from fewer different people than this.
     * One setting for crowd tags, product links and this, so the site cannot
     * say "people picked this" on less evidence in one place than in another.
     */
    private function minOwners(): int
    {
        return (int) config('giftcoves.list_signals.min_owners', 5);
    }

    private function key(Market $market, string $what): string
    {
        return 'crowd_picks:'.Cache::get(self::VERSION_KEY, '0').':'.$market->value.':'.$what;
    }
}
