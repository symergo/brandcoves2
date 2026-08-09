<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\SantaStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A gift exchange.
 *
 * Holds no products. Everything shoppable hangs off the members' own wishlists,
 * which is why this is not a `wishlist` row wearing a hat.
 *
 * @property SantaStatus $status
 */
class SecretSantaGroup extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $group): void {
            $group->invite_token ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'status' => SantaStatus::class,
            'exchange_date' => 'date',
            'drawn_at' => 'datetime',
        ];
    }

    /** @return HasMany<SecretSantaMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(SecretSantaMember::class, 'group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function organiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isOrganiser(?User $user): bool
    {
        return $user !== null && $this->owner_user_id === $user->id;
    }
}
