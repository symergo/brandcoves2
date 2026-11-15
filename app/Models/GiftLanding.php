<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Services\Gift\Suggestion;
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

    /**
     * Where the engine's answer for this page is kept (`budget` is the
     * `?budget=` value, null for the page itself).
     *
     * Keyed on `checked_at`, so the night's plan replaces yesterday's answer.
     * One place for the key because two things write it: the page on a visit,
     * and the nightly planner, which has just run the engine for this very
     * brief and stores what it got (2026-11-15). Before that the planner threw
     * its answer away and the first visitor of the day waited 5 to 15 seconds
     * for the engine to run again.
     */
    public function verdictsKey(?string $budget = null): string
    {
        return sprintf('bc:gift-landing:v2:%d:%d:%s', $this->id, $this->checked_at->getTimestamp(), $budget ?? 'all');
    }

    /**
     * What the page keeps of the engine's answer: which products, in which
     * order, what each has in common with the brief and whether other people
     * chose it for someone like this.
     *
     * @param  list<Suggestion>  $suggestions
     * @return list<array{id: int, fits: mixed, chosenByOthers: mixed}>
     */
    public static function verdicts(array $suggestions): array
    {
        return array_map(fn (Suggestion $s) => [
            'id' => $s->group->id,
            'fits' => $s->fits(),
            'chosenByOthers' => $s->chosenByOthers(),
        ], $suggestions);
    }

    public function toBrief(int $limit): TasteBrief
    {
        return TasteBrief::fromArray($this->brief ?? [], $this->market, $limit);
    }
}
