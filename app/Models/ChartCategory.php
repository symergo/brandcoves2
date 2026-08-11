<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\Source;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category a source charts separately — and the crawl frontier.
 *
 * Rows arrive by discovery rather than configuration: bol publishes no list of
 * category ids, so each is learned by pulling a chart and reading the relevant
 * categories off the response.
 */
class ChartCategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => Source::class,
            'market' => Market::class,
            'enabled' => 'boolean',
            'last_pulled_at' => 'datetime',
            'first_seen_at' => 'datetime',
        ];
    }

    /** @return HasMany<PopularRank, $this> */
    public function ranks(): HasMany
    {
        return $this->hasMany(PopularRank::class, 'category_external_id', 'external_id');
    }

    /** @param Builder<$this> $query */
    public function scopeForMarket(Builder $query, Market $market): void
    {
        $query->where('market', $market->value);
    }

    /** @param Builder<$this> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * The puller's work-list order: never pulled first, then stalest.
     *
     * A newly discovered category is worth more than a re-pull of one we already
     * hold, because the first pull is what makes it exist to us at all.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeStalestFirst(Builder $query): void
    {
        $query->orderByRaw('last_pulled_at IS NULL DESC')
            ->orderBy('last_pulled_at')
            ->orderBy('id');
    }
}
