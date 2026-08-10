<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One brand, in one market, with everything a brand page asserts about it.
 *
 * Refreshed nightly by `BrandStats`. Read-only from the request's point of view:
 * a brand page must render in one query, and every sentence of its copy is a
 * column here rather than an aggregate computed while a visitor waits.
 *
 * @property string $brand
 * @property string|null $slug
 * @property int $product_count
 * @property int $merchant_count
 * @property int|null $min_price
 * @property int|null $max_price
 * @property int $discounted_count
 * @property int $in_stock_count
 * @property int|null $best_discount_percent
 * @property string|null $top_category
 */
class BrandStat extends Model
{
    protected $table = 'brand_stats';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'aliases' => 'array',
            'categories' => 'array',
            'share' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * Every feed spelling that folds to this slug.
     *
     * What the brand page actually filters on. An Awin feed says
     * "Audio-Technica" and bol says "Audio Technica"; both are this brand, and a
     * page that showed only one of them would hide half the offers because two
     * merchants disagree about a hyphen.
     *
     * Falls back to the display name, so a row written before `aliases` existed —
     * or by a future path that forgets it — still filters to something rather
     * than to nothing.
     *
     * @return list<string>
     */
    public function brandSpellings(): array
    {
        $aliases = array_values(array_filter((array) $this->aliases, 'is_string'));

        return $aliases === [] ? [$this->brand] : $aliases;
    }

    /** @return BelongsTo<Merchant, $this> */
    public function topMerchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'top_merchant_id');
    }

    /** @param Builder<$this> $query */
    public function scopeForMarket(Builder $query, Market $market): void
    {
        $query->where('market', $market->value);
    }

    /**
     * Brands worth giving a page to.
     *
     * A brand with one product cannot support a page of copy about itself, and
     * publishing thousands of near-empty pages is the doorway-page pattern that
     * gets a whole domain discounted. Three is low, but it is the difference
     * between "a page about a brand" and "a page with one product on it".
     *
     * @param  Builder<$this>  $query
     */
    public function scopePageworthy(Builder $query): void
    {
        $query->where('product_count', '>=', 3);
    }
}
