<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A single-use magic-link token.
 *
 * The plaintext is returned once, at creation, and never stored — only its
 * hash. A database leak therefore does not hand an attacker live login links.
 */
class LoginToken extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * Issue a token, returning the plaintext to put in the email.
     *
     * @return array{token: string, model: self}
     */
    public static function issue(string $email, ?string $ip = null, ?string $name = null): array
    {
        $email = mb_strtolower(trim($email));

        // Invalidate anything outstanding for this address. Requesting a new
        // link is the normal response to "it didn't arrive", and leaving the
        // old ones live widens the window for no benefit.
        static::query()->where('email', $email)->whereNull('used_at')->delete();

        $plaintext = Str::random(64);

        $model = static::create([
            'email' => $email,
            'name' => $name,
            'token_hash' => hash('sha256', $plaintext),
            // Short: long enough to switch to an email client, not long enough
            // to sit in an inbox as a standing key to the account.
            'expires_at' => now()->addMinutes(15),
            'requested_ip' => $ip,
        ]);

        return ['token' => $plaintext, 'model' => $model];
    }

    /**
     * Consume a token, or null if it is unknown, expired or already used.
     *
     * The single-use check and the write are one conditional UPDATE: two
     * requests racing on the same link — a mail client prefetching it and the
     * human clicking it — must not both succeed.
     */
    public static function consume(string $plaintext): ?self
    {
        $hash = hash('sha256', $plaintext);

        $claimed = static::query()
            ->where('token_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now(), 'updated_at' => now()]);

        return $claimed === 1
            ? static::query()->where('token_hash', $hash)->first()
            : null;
    }
}
