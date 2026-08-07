<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'preferred_market', 'email_opt_in', 'avatar_url'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferred_market' => Market::class,
            'is_admin' => 'boolean',
            'email_opt_in' => 'boolean',
        ];
    }

    /** @return HasMany<Wishlist, $this> */
    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class, 'owner_user_id');
    }

    /** @return HasMany<Recipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class, 'owner_user_id');
    }

    /** @return HasMany<Notification, $this> */
    public function inbox(): HasMany
    {
        return $this->hasMany(Notification::class)->latest();
    }

    /**
     * Filament admin access.
     *
     * Deliberately a database flag with no self-service path: the admin panel
     * exposes connector credentials, AI spend and the whole catalogue, so
     * granting access is a manual act.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin === true;
    }

    /**
     * Identity used for wishlist claims — hashed, never stored in the clear.
     * Keyed on the immutable id rather than the email, which can change.
     */
    public function claimIdentity(): string
    {
        return 'user:'.$this->getKey();
    }
}
