<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Services\Gift\TasteBrief;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A gift landing page that exists: "gifts for dad who loves cooking" in one
 * market, recorded because the catalogue can fill it.
 *
 * Written only by App\Jobs\PlanGiftLandingPages; read by the landing page,
 * the sitemap and hreflang. See docs/features/gift-landing-pages.md.
 *
 * @property array<string, mixed> $brief
 */
class GiftLanding extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'recipient' => RecipientType::class,
            'interest' => Interest::class,
            'brief' => 'array',
            'product_count' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public static function lookup(Market $market, RecipientType $recipient, ?Interest $interest): ?self
    {
        return static::query()
            ->where('market', $market->value)
            ->where('recipient', $recipient->value)
            ->when(
                $interest === null,
                fn (Builder $q) => $q->whereNull('interest'),
                fn (Builder $q) => $q->where('interest', $interest?->value),
            )
            ->first();
    }

    /** @param Builder<$this> $query */
    public function scopeForMarket(Builder $query, Market $market): void
    {
        $query->where('market', $market->value);
    }

    public function toBrief(int $limit): TasteBrief
    {
        return TasteBrief::fromArray($this->brief ?? [], $this->market, $limit);
    }
}
