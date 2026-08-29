<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DailyPickFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One surprising product, on one day.
 *
 * Every pick gets a permanent shareable page — each "look at this insane thing"
 * share is then an SEO and social asset rather than a dead link.
 */
class DailyPick extends Model
{
    /** @use HasFactory<DailyPickFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'score_breakdown' => 'array',
            /*
             * Dimmed rather than dropped, on an article.
             *
             * A Daily hides a find that has sold out — the page is one
             * morning's snapshot and a gap beats a dead card. A guide is an
             * argument about what to buy, and silently removing the entry it
             * argued for leaves the reasoning with a hole in it.
             */
            'unavailable' => 'boolean',
        ];
    }

    /** @return BelongsTo<DailyPickSet, $this> */
    public function set(): BelongsTo
    {
        return $this->belongsTo(DailyPickSet::class, 'set_id');
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    /** @return HasMany<PickReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(PickReaction::class, 'pick_id');
    }

    /**
     * Amazon picks store the DECISION, not the catalogue: we keep the ASIN and
     * the scoring metadata, then re-fetch title, image, price and availability
     * live at render. A failed fetch hides the pick rather than showing stale
     * Amazon data, which their terms do not allow.
     */
    public function requiresLiveFetch(): bool
    {
        return $this->amazon_asin !== null;
    }
}
