<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned Cove — what a day is *for*, decided before the day arrives.
 *
 * An intention, not an edition. The builder reads it and does the work; the
 * edition is still the thing that gets published. That separation is what lets
 * a plan exist for a date the catalogue later cannot fill without leaving an
 * empty page behind.
 *
 * @property list<string> $queries
 * @property list<int> $pinned_group_ids
 */
class CovePlan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'drop_date' => 'date',
            'queries' => 'array',
            'pinned_group_ids' => 'array',
        ];
    }

    /** @return BelongsTo<DailyPickSet, $this> */
    public function edition(): BelongsTo
    {
        return $this->belongsTo(DailyPickSet::class, 'edition_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The plan the builder should use for this date, if any.
     *
     * `approved` only. A draft is someone thinking out loud, and the clock
     * coming round is not a reason to publish it.
     */
    public static function approvedFor(Market $market, CarbonImmutable $date): ?self
    {
        return static::query()
            ->where('market', $market->value)
            ->where('drop_date', $date->toDateString())
            ->where('status', 'approved')
            ->first();
    }

    /** @param Builder<$this> $query */
    public function scopeQueued(Builder $query): void
    {
        // Undated and approved: ideas waiting for a slot.
        $query->whereNull('drop_date')->where('status', 'approved');
    }

    /** @param Builder<$this> $query */
    public function scopeUpcoming(Builder $query): void
    {
        $query->whereNotNull('drop_date')
            ->whereDate('drop_date', '>=', today())
            ->orderBy('drop_date');
    }

    public function isDaily(): bool
    {
        return $this->drop_date !== null;
    }
}
