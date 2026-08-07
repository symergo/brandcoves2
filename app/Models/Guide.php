<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\PublishStatus;
use Database\Factories\GuideFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A buying guide, generated from what people actually search for on this site.
 *
 * The demand signal comes from {@see SearchLog}, which is why these rank: the
 * topic is evidenced rather than guessed.
 */
class Guide extends Model
{
    /** @use HasFactory<GuideFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'status' => PublishStatus::class,
            'source_queries' => 'array',
            'faq' => 'array',
            'published_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /** @return HasMany<GuideItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GuideItem::class)->orderBy('rank');
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

    /**
     * Guides decay: products sell out and feeds drop them. A guide whose items
     * are half unavailable is worse than no guide, so a monthly job re-checks
     * anything older than the refresh window.
     */
    public function needsFreshnessCheck(): bool
    {
        $days = (int) config('brandcoves.guides.freshness_check_days');

        return $this->last_checked_at === null
            || $this->last_checked_at->lt(now()->subDays($days));
    }
}
