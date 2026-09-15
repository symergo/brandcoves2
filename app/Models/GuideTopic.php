<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * A candidate guide topic, clustered from real search queries and ranked.
 *
 * Surfaced in admin so a human queues or rejects one before anything is
 * generated — an automated pipeline that publishes unreviewed pages is how a
 * site fills up with thin content.
 */
class GuideTopic extends Model
{
    protected $guarded = [];

    /*
     * `last_attempt_at` and `attempts` are still columns, and nothing reads or
     * writes them. They held the "recently attempted" rule — a topic whose build
     * failed sat out fourteen days — written for the builder that took one guide
     * at a time off the head of a queue. Nothing had recorded an attempt since
     * that builder went, so the rule could never trigger, and it was removed on
     * 2026-09-14. The columns go a release later (docs/TODO.md), so a rollback
     * onto the build that still filtered on them does not meet a missing column.
     */

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'member_queries' => 'array',
        ];
    }

    /**
     * The Cove this topic became, if it became one.
     *
     * Was a `belongsTo(Guide::class)` on `guide_id`, and both went with the
     * fold's contract migration: a guide is a `daily_pick_sets` row now, and the
     * topic reaches it through the **plan** it drafted rather than through a
     * second foreign key of its own. `TopicPlanner` sets `plan_id`; the plan
     * carries `edition_id` once it is built.
     *
     * @return HasOneThrough<DailyPickSet, CovePlan, $this>
     */
    public function cove(): HasOneThrough
    {
        return $this->hasOneThrough(
            DailyPickSet::class,
            CovePlan::class,
            'id',          // cove_plans.id
            'id',          // daily_pick_sets.id
            'plan_id',     // guide_topics.plan_id
            'edition_id',  // cove_plans.edition_id
        );
    }

    /**
     * A topic is only worth writing if we can actually fill it. High search
     * volume with no matching products is a catalogue gap, not a guide.
     */
    public function isViable(): bool
    {
        return $this->available_products >= (int) config('giftcoves.guides.min_products');
    }
}
