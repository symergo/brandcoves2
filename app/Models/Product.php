<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An OFFER: one merchant selling one thing in one market.
 *
 * Despite the name, this is not "a product" in the sense a shopper means — that
 * is {@see ProductGroup}. Several Product rows for the same physical object are
 * exactly what makes price comparison possible.
 *
 * @property int $id
 * @property Source $source
 * @property Market $market
 * @property int|null $price cents
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * A stored generated column — Postgres computes it and rejects any write.
     */
    protected $with = [];

    protected function casts(): array
    {
        return [
            'source' => Source::class,
            'market' => Market::class,
            'availability' => Availability::class,
            'status' => ProductStatus::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<Feed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }

    /** @return HasMany<PriceHistory, $this> */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ProductStatus::Active->value);
    }

    /** @param Builder<$this> $query */
    public function scopeForMarket(Builder $query, Market $market): void
    {
        $query->where('market', $market->value);
    }

    /** @param Builder<$this> $query */
    public function scopeInStock(Builder $query): void
    {
        $query->where('availability', Availability::InStock->value);
    }

    /**
     * Whether this offer's affiliate URL is safe to redirect a visitor to.
     *
     * These URLs come from third-party feeds. HTML-escaping alone would happily
     * preserve a `javascript:` scheme, so the click-out redirector checks this
     * before ever emitting a Location header.
     */
    public function hasSafeAffiliateUrl(): bool
    {
        $scheme = parse_url($this->affiliate_url, PHP_URL_SCHEME);

        return is_string($scheme) && strtolower($scheme) === 'https';
    }

    /**
     * Where the "go to shop" button should point.
     *
     * Most sources go through our redirector, which is where the affiliate-URL
     * scheme check and click logging live. Amazon requires Associates links to
     * be direct and unobscured, so its offers return the raw affiliate URL and
     * the click is recorded by a beacon instead.
     *
     * Returns null when the stored URL is unsafe, so a caller cannot render a
     * link at all rather than rendering a dangerous one.
     */
    public function outboundUrl(): ?string
    {
        if (! $this->source->requiresDirectLink()) {
            return route('go', ['market' => $this->market->value, 'offer' => $this->id]);
        }

        // The redirector normally performs this check. On the direct path there
        // is nothing between us and the browser, so it happens here.
        return $this->hasSafeAffiliateUrl() ? $this->affiliate_url : null;
    }

    /**
     * The merchant's real domain, taken from their own deep link.
     *
     * Never derive this from affiliate_url: that is a network tracking URL
     * (awin1.com/pclick.php), so it yields the network's favicon for every
     * merchant instead of the shop's.
     */
    public function merchantDomain(): ?string
    {
        if (! is_string($this->merchant_deep_link) || $this->merchant_deep_link === '') {
            return null;
        }

        $host = parse_url($this->merchant_deep_link, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }
}
