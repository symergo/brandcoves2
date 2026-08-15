<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AnonymousIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Who owns a list — a signed-in user or an anonymous visitor.
 *
 * The wishlist feature has to work before signup, so every ownership check has
 * two shapes. Centralising them means a controller cannot accidentally handle
 * only the signed-in case and silently lose an anonymous visitor's list.
 */
final readonly class Owner
{
    public function __construct(
        public ?User $user,
        public ?AnonymousIdentity $anonymous,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            user: $request->user(),
            anonymous: $request->attributes->get('anonymous_identity'),
        );
    }

    public function exists(): bool
    {
        return $this->user !== null || $this->anonymous !== null;
    }

    public function isSignedIn(): bool
    {
        return $this->user !== null;
    }

    /**
     * Columns for creating a row owned by whoever this is.
     *
     * The column names are parameters because two table families use different
     * ones: wishlists and recipients say `owner_user_id` / `owner_anon_id`
     * (they have a CHECK constraint naming exactly one owner), while
     * challenge_attempts says `user_id` / `anon_id`. Passing the names in beats
     * a second copy of this class, and beats renaming live columns to suit a
     * helper.
     *
     * @return array<string, mixed>
     */
    public function attributes(string $userColumn = 'owner_user_id', string $anonColumn = 'owner_anon_id'): array
    {
        return $this->user !== null
            ? [$userColumn => $this->user->id, $anonColumn => null]
            : [$userColumn => null, $anonColumn => $this->anonymous?->getKey()];
    }

    /**
     * Constrain a query to rows this owner may see.
     *
     * Deliberately returns nothing when there is no owner at all, rather than
     * everything — the failure mode of a missing scope should be an empty list,
     * never someone else's.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public function scope(
        Builder $query,
        string $userColumn = 'owner_user_id',
        string $anonColumn = 'owner_anon_id',
    ): Builder {
        if ($this->user !== null) {
            return $query->where($userColumn, $this->user->id);
        }

        if ($this->anonymous !== null) {
            return $query->where($anonColumn, $this->anonymous->getKey());
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * The identity a claim is hashed from.
     *
     * Stable per person: a signed-in user keys on their immutable id, an
     * anonymous visitor on their cookie identity. Both are hashed before
     * storage so the list owner can never learn who claimed what.
     */
    public function claimIdentity(): ?string
    {
        return $this->user?->claimIdentity()
            ?? ($this->anonymous === null ? null : 'anon:'.$this->anonymous->getKey());
    }

    /**
     * A one-way, per-purpose identity hash.
     *
     * `$purpose` is mixed in so the same visitor produces a different hash for a
     * gift claim than for a pick reaction. Without it, two tables would share a
     * value and either could be used to look the visitor up in the other —
     * a join nobody intended and nobody would notice.
     */
    public function identityHash(string $purpose): ?string
    {
        $identity = $this->claimIdentity();

        if ($identity === null) {
            return null;
        }

        return hash_hmac(
            'sha256',
            $purpose.'|'.$identity,
            (string) config('giftcoves.wishlist.claim_hash_secret'),
        );
    }
}
