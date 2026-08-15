<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\Source;
use Database\Factories\FeedFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One configured advertiser feed, per market.
 *
 * Onboarding a merchant is a row here plus an admin action — never a code
 * change. Catalogue breadth is the single biggest constraint on Daily Picks and
 * the gift engine, so adding advertisers has to be friction-free.
 */
class Feed extends Model
{
    /** @use HasFactory<FeedFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => Source::class,
            'market' => Market::class,
            'enabled' => 'boolean',
            'column_map' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @param Builder<$this> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /** Stable per feed per market, so a resumed run finds its own cursor. */
    public function jobKey(): string
    {
        return sprintf('%s:%s:%s', $this->source->value, $this->external_feed_id, $this->market->value);
    }

    /**
     * The affiliate account this feed is reachable through.
     *
     * An advertiser can only be downloaded with the credentials of the
     * publisher account actually joined to them — a different affiliate member
     * id sees a completely different advertiser list, so using the wrong key
     * yields a 401 or an empty file rather than a partial result.
     *
     * @return array{label: string, api_token: string, publisher_id: string|null}|null
     */
    public function account(): ?array
    {
        $accounts = (array) config('giftcoves.connectors.awin.accounts', []);

        return $accounts[$this->account ?? 'default'] ?? null;
    }

    public function apiToken(): ?string
    {
        $token = $this->account()['api_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}
