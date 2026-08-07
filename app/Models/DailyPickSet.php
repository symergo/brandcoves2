<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\PublishStatus;
use Database\Factories\DailyPickSetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
