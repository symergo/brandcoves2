<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\Source;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product's position on one chart on one day.
 *
 * Holds the decision and nothing a visitor reads — see the migration for why
 * that shape is load-bearing rather than merely lean.
 */
class PopularRank extends Model
{
    /** The market-wide chart. Not null: see the migration. */
    public const OVERALL = '*';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => Source::class,
            'market' => Market::class,
            'rank' => 'integer',
            'captured_on' => 'date',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    /** @param Builder<$this> $query */
    public function scopeForMarket(Builder $query, Market $market): void
    {
        $query->where('market', $market->value);
    }

    /**
     * The most recent snapshot date for a market, or null if there is none.
     *
     * Charts are pulled per category and a run can be cut short by a rate limit,
     * so "the latest snapshot" is a date rather than a run id — every category
     * pulled today shares one, and yesterday's leftovers do not contaminate it.
     */
    public static function latestCapturedOn(Market $market, ?Source $source = null): ?string
    {
        $date = static::query()
            ->forMarket($market)
            ->when($source !== null, fn (Builder $q) => $q->where('source', $source->value))
            ->max('captured_on');

        return $date === null ? null : (string) $date;
    }
}
