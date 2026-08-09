<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\PublishStatus;
use Database\Factories\DailyPickSetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One day's themed set of picks.
 *
 * The theme is doing quiet but essential work: it gives the feature an editorial
 * voice instead of a random-product firehose, and it is the reason to come back
 * tomorrow — the way you'd check a daily column.
 */
class DailyPickSet extends Model
{
    /** @use HasFactory<DailyPickSetFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'status' => PublishStatus::class,
            'drop_date' => 'date',
            'published_at' => 'datetime',
        ];
    }

    /** @return HasMany<DailyPick, $this> */
    public function picks(): HasMany
    {
        return $this->hasMany(DailyPick::class, 'set_id')->orderBy('rank');
    }

    /**
     * The buying guide attached to this edition.
     *
     * Nullable, and often the *previous* edition's guide: topics ripen at the
     * speed of search volume, not at the speed of the calendar, and a guide a
     * week is a healthier rate than a guide a day.
     *
     * @return BelongsTo<Guide, $this>
     */
    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function challengeGroup(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'challenge_group_id');
    }

    /** @return HasMany<ChallengeAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(ChallengeAttempt::class, 'set_id');
    }

    /**
     * Is this edition live yet?
     *
     * The same three conditions as the `published` scope, asked of one row.
     * A preview banner has to know whether it is looking at a draft, and
     * re-deriving that from `status` alone would call a scheduled-but-not-yet
     * dropped edition published.
     */
    public function isPublished(): bool
    {
        return $this->status === PublishStatus::Published
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    /** @param Builder<$this> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PublishStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /** @param Builder<$this> $query */
    public function scopeForMarket(Builder $query, Market $market): void
    {
        $query->where('market', $market->value);
    }
}
