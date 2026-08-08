<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Someone who wants the Daily Cove by email.
 *
 * Unconfirmed until they click the link in the one mail we send them. See the
 * migration for why that is not negotiable.
 *
 * @property string $email
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $unsubscribed_at
 */
class CoveSubscriber extends Model
{
    protected $guarded = [];

    /**
     * The address never leaves the server in a payload.
     *
     * Nothing currently serialises a subscriber, and that is exactly when to add
     * this — the guard costs nothing now and is the sort of thing nobody
     * remembers to add before the admin screen that leaks it.
     *
     * @var list<string>
     */
    protected $hidden = ['email', 'confirm_token', 'unsubscribe_token', 'signup_ip'];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'confirm_sent_at' => 'datetime',
            'last_sent_on' => 'date',
        ];
    }

    /**
     * A confirmation link is single-use and expires.
     *
     * 48 hours: long enough for someone who signs up on Friday evening, short
     * enough that a link sitting in an abandoned mailbox stops working.
     */
    public const CONFIRM_TTL_HOURS = 48;

    public static function newToken(): string
    {
        // 40 bytes of randomness, hex-encoded to 80 chars, truncated to the
        // column width. Still 32 bytes of entropy — unguessable, and short
        // enough not to wrap in an email client.
        return substr(bin2hex(random_bytes(40)), 0, 64);
    }

    public static function normaliseEmail(string $email): string
    {
        // Lowercased so "Sam@Example.com" and "sam@example.com" cannot become two
        // subscriptions and therefore two copies of every edition.
        return Str::lower(trim($email));
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null && $this->unsubscribed_at === null;
    }

    public function confirmTokenIsFresh(): bool
    {
        return $this->confirm_sent_at !== null
            && $this->confirm_sent_at->gt(now()->subHours(self::CONFIRM_TTL_HOURS));
    }

    /** @param Builder<$this> $query */
    public function scopeForMarket(Builder $query, Market $market): void
    {
        $query->where('market', $market->value);
    }

    /**
     * Everyone who should receive today's edition.
     *
     * `last_sent_on` is the idempotency guard: a queued job that is retried after
     * a partial send must not mail the first half of the list twice.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDueFor(Builder $query, string $date): void
    {
        $query->whereNotNull('confirmed_at')
            ->whereNull('unsubscribed_at')
            ->where(fn (Builder $q) => $q->whereNull('last_sent_on')->orWhere('last_sent_on', '<', $date));
    }
}
