<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * How long a failed build keeps a topic out of the queue.
     *
     * A topic is thin because the catalogue is thin. Feeds refresh twice a day,
     * but a category's *breadth* changes on the scale of weeks, so retrying
     * nightly would spend the day's slot re-discovering the same shortfall — and
     * that is precisely the failure this exists to prevent, since one unbuildable
     * topic at the head of the queue makes every topic behind it unreachable.
     *
     * Fourteen days: short enough to notice a new advertiser's feed within a
     * fortnight, long enough that the queue keeps moving.
     */
    public const RETRY_AFTER_DAYS = 14;

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'member_queries' => 'array',
            'last_attempt_at' => 'datetime',
        ];
    }

    /**
     * Topics the builder has not just failed on.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNotRecentlyAttempted(Builder $query): void
    {
        $query->where(fn ($q) => $q
            ->whereNull('last_attempt_at')
            ->orWhere('last_attempt_at', '<', now()->subDays(self::RETRY_AFTER_DAYS)));
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
