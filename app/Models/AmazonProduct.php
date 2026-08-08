<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AmazonLocale;
use App\Enums\Market;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One ASIN, classified once, shown in whichever locale the visitor picks.
 *
 * Holds the decision and nothing a visitor reads. Title, price, image and
 * availability are re-fetched live per locale at render, because Amazon does
 * not permit them to be mirrored — see docs/features/amazon-compliance.md.
 *
 * @property list<string> $seen_in_locales
 */
class AmazonProduct extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'giftable' => 'boolean',
            'surprise_breakdown' => 'array',
            'seen_in_locales' => 'array',
            'classified_at' => 'datetime',
            'first_seen_at' => 'datetime',
        ];
    }

    /**
     * The locales worth offering for this product, primary first.
     *
     * Every locale is offered, not just the ones we have seen it in: a shopper
     * asking for the German price is entitled to find out it is unavailable
     * there, and `seen_in_locales` is a hint refreshed on a schedule rather
     * than a fact. Known-stocked ones sort ahead of the rest so the useful tabs
     * come first.
     *
     * @return list<AmazonLocale>
     */
    public function localesFor(Market $market): array
    {
        $seen = array_flip((array) $this->seen_in_locales);
        $locales = AmazonLocale::selectableFor($market);

        // Stable sort: primary stays first, then anything we have seen, then
        // the rest in their declared order.
        usort($locales, function (AmazonLocale $a, AmazonLocale $b) use ($seen, $market): int {
            $primary = AmazonLocale::primaryFor($market);

            if ($a === $primary) {
                return -1;
            }
            if ($b === $primary) {
                return 1;
            }

            return (isset($seen[$b->value]) ? 1 : 0) <=> (isset($seen[$a->value]) ? 1 : 0);
        });

        return $locales;
    }

    /** @param Builder<$this> $query */
    public function scopeGiftable(Builder $query): void
    {
        $query->where('giftable', true);
    }

    /**
     * Classified recently enough to trust.
     *
     * The rules change more often than the products do, so a verdict has a
     * shelf life even though the ASIN does not.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeStale(Builder $query, int $days = 30): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereNull('classified_at')
            ->orWhere('classified_at', '<', now()->subDays($days)));
    }
}
