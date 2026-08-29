<?php

declare(strict_types=1);

namespace App\Services\Curation;

use App\Enums\CoveKind;
use App\Enums\Market;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "You have already used this one."
 *
 * The mistake a curator actually makes is not picking a bad product — it is
 * picking a good one twice. The 90-day repeat memory catches that for anything
 * the *engine* chooses and deliberately does not for anything a person chooses,
 * because the whole point of curation is to override a score. So the rule that
 * protects the machine protects nobody here, and the only defence left is
 * telling the person.
 *
 * Two kinds of collision, both worth knowing and neither worth blocking:
 *
 *   - **Already on another plan.** The one that matters, because it has not
 *     happened yet and is free to change. A calendar drafted a hundred days
 *     ahead makes this easy to do by accident.
 *   - **Published recently.** Older, cheaper to accept, but a returning reader
 *     notices a repeat faster than anything else on the page — the odd ones are
 *     exactly what they remember.
 *
 * Advisory, never a filter. An editor putting the same kettle on two Coves a
 * month apart may have a reason, and a screen that refuses would be wrong more
 * often than it was right.
 */
class ScheduleConflicts
{
    /**
     * A sentence per group id, for the ids that collide with something.
     *
     * One query per kind rather than per product: this runs for a whole page of
     * search results and a whole shortlist, and a lookup per card would be an
     * N+1 on the screen's hottest path.
     *
     * @param  list<int>  $groupIds
     * @return array<int, string>
     */
    public function for(Market $market, array $groupIds, ?int $exceptPlanId = null): array
    {
        $groupIds = array_values(array_unique(array_filter($groupIds)));

        if ($groupIds === []) {
            return [];
        }

        // Published first, so a plan collision overwrites it below: a date that
        // has not happened yet is the one still worth acting on.
        $conflicts = $this->published($market, $groupIds);

        foreach ($this->planned($market, $groupIds, $exceptPlanId) as $groupId => $sentence) {
            $conflicts[$groupId] = $sentence;
        }

        return $conflicts;
    }

    /**
     * @param  list<int>  $groupIds
     * @return array<int, string>
     */
    private function planned(Market $market, array $groupIds, ?int $exceptPlanId): array
    {
        return DB::table('cove_plan_items as i')
            ->join('cove_plans as p', 'p.id', '=', 'i.plan_id')
            ->where('p.market', $market->value)
            // A rejected plan is a decision not to run something. Warning about
            // a collision with one would be warning about nothing.
            ->where('p.status', '!=', 'rejected')
            ->when($exceptPlanId !== null, fn ($q) => $q->where('p.id', '!=', $exceptPlanId))
            ->whereIn('i.group_id', $groupIds)
            ->select('i.group_id', 'p.drop_date', 'p.title', 'p.kind')
            ->get()
            ->keyBy('group_id')
            ->map(fn ($row) => $row->kind === CoveKind::Persona->value
                ? 'already on “'.$row->title.'”'
                : 'already on '.CarbonImmutable::parse($row->drop_date)->format('j M'))
            ->all();
    }

    /**
     * @param  list<int>  $groupIds
     * @return array<int, string>
     */
    private function published(Market $market, array $groupIds): array
    {
        $since = now()->subDays((int) config('giftcoves.picks.memory_days'));

        return DB::table('daily_picks as d')
            ->join('daily_pick_sets as s', 's.id', '=', 'd.set_id')
            ->where('s.market', $market->value)
            // Dailies only: a persona is permanent, so "it is on the herbalist
            // shelf" is not a staleness warning and would fire forever.
            ->where('s.kind', CoveKind::Daily->value)
            ->whereNotNull('s.drop_date')
            ->where('s.drop_date', '>=', $since->toDateString())
            ->whereIn('d.group_id', $groupIds)
            ->select('d.group_id', 's.drop_date')
            ->get()
            ->keyBy('group_id')
            ->map(fn ($row) => 'ran '.CarbonImmutable::parse($row->drop_date)->format('j M'))
            ->all();
    }
}
