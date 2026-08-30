<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IdentityKind;
use App\Enums\Market;
use Database\Factories\ProductGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical product, in one market.
 *
 * This — not Product — is what search results, product pages, gift picks and
 * guides operate on. A group holds many offers; comparing them is the point.
 *
 * @property int $id
 * @property Market $market
 * @property string $identity_key
 * @property IdentityKind $identity_kind
 * @property int|null $min_price cents
 * @property int|null $median_price cents
 */
class ProductGroup extends Model
{
    /** @use HasFactory<ProductGroupFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'identity_kind' => IdentityKind::class,
            'surprise_breakdown' => 'array',
            'in_stock' => 'boolean',
            'giftable' => 'boolean',
            'worth_showing' => 'boolean',
            'first_seen_at' => 'datetime',
        ];
    }

    /** @return HasMany<Product, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Product::class, 'group_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function bestOffer(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'best_offer_id');
    }

    /** @return HasMany<WishlistItem, $this> */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class, 'group_id');
    }

    /** @param Builder<$this> $query */
    public function scopeForMarket(Builder $query, Market $market): void
    {
        $query->where('market', $market->value);
    }

    /**
     * Groups worth showing: in stock, priced, and with an image.
     *
     * The image requirement is not cosmetic — a card with no image reads as
     * broken, and feeds supply plenty of rows with none.
     */
    /** @param Builder<$this> $query */
    public function scopePresentable(Builder $query): void
    {
        $query->where('in_stock', true)
            ->whereNotNull('min_price')
            ->whereNotNull('image_url');
    }

    /** @param Builder<$this> $query */
    public function scopeComparable(Builder $query): void
    {
        $query->where('merchant_count', '>', 1);
    }

    /**
     * The gift engine's filter. Carries the price ceiling.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeGiftable(Builder $query): void
    {
        $query->where('giftable', true);
    }

    /**
     * The editorial surfaces' filter. Everything `giftable()` allows, plus the
     * things that are only excluded for costing too much to suggest.
     *
     * Not the same question, which is why it is not the same column: a €700
     * espresso machine is a bad gift suggestion and a good thing to put in a
     * Cove. See docs/features/giftability.md.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWorthShowing(Builder $query): void
    {
        $query->where('worth_showing', true);
    }

    /**
     * Discount against the 30-day median rather than a merchant-supplied "was"
     * price, which is frequently fiction.
     */
    public function discountPercent(): ?int
    {
        if ($this->median_price === null || $this->min_price === null) {
            return null;
        }
        if ($this->median_price <= 0 || $this->min_price >= $this->median_price) {
            return null;
        }

        // Floor, never round: a badge must not overstate a saving we would then
        // have to defend.
        $percent = (int) floor((($this->median_price - $this->min_price) / $this->median_price) * 100);

        // A saving of less than one percent floors to zero, and "0% off" is a
        // badge that claims nothing while looking exactly like one that claims
        // something. The price is a few cents under the median; there is no
        // discount to announce, so there is no badge.
        return $percent > 0 ? $percent : null;
    }

    public function hasMultipleMerchants(): bool
    {
        return $this->merchant_count > 1;
    }
}
