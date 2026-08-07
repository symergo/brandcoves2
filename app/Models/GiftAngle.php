<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Interest (+ optional vibe) → the search queries that retrieve candidates.
 *
 * This table is the reason the gift engine can be fast, pure and free at
 * request time. Expanding "photography, beautiful" into a useful set of product
 * queries is a natural job for a language model; doing it per request would put
 * seconds and money on a page that must render in milliseconds. So a nightly
 * job widens the map and the engine only ever reads it.
 *
 * @property Market $market
 * @property list<string> $queries
 */
class GiftAngle extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'queries' => 'array',
        ];
    }

    /** @param Builder<$this> $query */
    public function scopeForMarket(Builder $query, Market $market): void
    {
        $query->where('market', $market->value);
    }
}
