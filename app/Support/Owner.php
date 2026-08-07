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

    /** Columns for creating a row owned by whoever this is. */
    public function attributes(): array
    {
        return $this->user !== null
            ? ['owner_user_id' => $this->user->id, 'owner_anon_id' => null]
            : ['owner_user_id' => null, 'owner_anon_id' => $this->anonymous?->getKey()];
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
    public function scope(Builder $query): Builder
    {
        if ($this->user !== null) {
            return $query->where('owner_user_id', $this->user->id);
        }

        if ($this->anonymous !== null) {
            return $query->where('owner_anon_id', $this->anonymous->getKey());
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
}
