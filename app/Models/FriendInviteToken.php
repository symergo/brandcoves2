<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Social\InviteAcceptance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The "Uitnodiging aannemen" button in an invitation email: a single-use key
 * that creates the invited address's account and signs it in.
 *
 * Built like {@see LoginToken}: the plaintext goes into the email once and
 * only its sha256 is stored, and using it is one conditional UPDATE. Unlike a
 * magic link it lives `giftcoves.invites.accept_days` (14), because an
 * invitation is read days later; and it never signs in an account that
 * already exists, see {@see InviteAcceptance}.
 */
class FriendInviteToken extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /** Issue a token for this invitation, returning the plaintext for the email. */
    public static function issue(User $inviter, string $email): string
    {
        $plaintext = Str::random(64);

        static::query()->create([
            'inviter_id' => $inviter->id,
            'email' => mb_strtolower(trim($email)),
            'token_hash' => hash('sha256', $plaintext),
            'expires_at' => now()->addDays((int) config('giftcoves.invites.accept_days', 14)),
        ]);

        return $plaintext;
    }

    /** The row behind a plaintext token, whatever its state. */
    public static function findByPlaintext(string $plaintext): ?self
    {
        return static::query()->where('token_hash', hash('sha256', $plaintext))->first();
    }

    /** Unused and unexpired. Reading only: nothing is consumed. */
    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * Consume a token, or null if it is unknown, expired or already used.
     *
     * The check and the write are one conditional UPDATE, so two requests
     * racing on the same button (a double press, two tabs) cannot both win.
     */
    public static function consume(string $plaintext): ?self
    {
        $hash = hash('sha256', $plaintext);

        $claimed = static::query()
            ->where('token_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        return $claimed === 1
            ? static::query()->where('token_hash', $hash)->first()
            : null;
    }
}
