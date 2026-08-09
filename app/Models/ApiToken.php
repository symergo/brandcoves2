<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A bearer credential for the editorial API.
 *
 * The plaintext exists exactly once, in the output of `bc:api-token`. Only its
 * SHA-256 is stored, which is also why lookup is by hash equality rather than by
 * iterating rows and comparing: the index does the work, and there is no
 * timing-sensitive comparison to get wrong.
 *
 * @property list<string> $abilities
 */
class ApiToken extends Model
{
    protected $guarded = [];

    /**
     * Read the catalogue and everything already published.
     *
     * Separate from write because the grounding endpoints are the ones a
     * writer hits constantly — product lookup, ripe topics, yesterday's
     * edition — and a read-only key for exploring is a useful thing to be able
     * to hand out.
     */
    public const READ = 'editorial.read';

    /** Create and edit drafts. Never reaches a reader on its own. */
    public const WRITE = 'editorial.write';

    /**
     * Approve a plan, publish a guide, build an edition.
     *
     * The gate that makes an automated writer safe by default: without this
     * ability everything it produces waits in Filament for a person.
     */
    public const PUBLISH = 'editorial.publish';

    /** @return list<string> */
    public static function abilities(): array
    {
        return [self::READ, self::WRITE, self::PUBLISH];
    }

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Mint a token, returning the plaintext to print once.
     *
     * The `bc_` prefix is not decoration. It makes the string recognisable in a
     * log, a paste and a secret scanner, which is how a leaked key gets noticed
     * by something other than the person who leaked it.
     *
     * @param  list<string>  $abilities
     * @return array{token: string, model: self}
     */
    public static function issue(
        string $name,
        array $abilities,
        ?CarbonInterface $expiresAt = null,
        ?int $createdBy = null,
    ): array {
        $plaintext = 'bc_'.Str::random(48);

        $model = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plaintext),
            'abilities' => array_values(array_intersect(self::abilities(), $abilities)),
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ]);

        return ['token' => $plaintext, 'model' => $model];
    }

    /** The live token for a plaintext string, or null if unknown, revoked or expired. */
    public static function resolve(string $plaintext): ?self
    {
        $token = static::query()
            ->where('token_hash', hash('sha256', $plaintext))
            ->first();

        return $token?->isUsable() === true ? $token : null;
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function can(string $ability): bool
    {
        return in_array($ability, (array) $this->abilities, true);
    }

    /**
     * Record that the key is alive, at most once a minute.
     *
     * A write on every request turns a read-only lookup endpoint into a write
     * endpoint, and the question this column answers — "is anything still using
     * this key?" — does not need second resolution.
     */
    public function touchUsage(): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
