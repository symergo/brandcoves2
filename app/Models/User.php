<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'preferred_market', 'email_opt_in', 'avatar_url'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
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
     * The name Filament shows in the user menu and the default avatar.
     *
     * `users.name` is nullable — this site signs people in by magic link, and a
     * shopper never gives a name. Filament, however, declares
     * `getUserName(): string` and blew up with a TypeError on every panel page
     * for an admin who had none. A 500 immediately after a successful login,
     * which reads as "login is broken" rather than "this row lacks a name".
     *
     * The email's local part is a better fallback than a placeholder: it is
     * recognisably *them*, and the alternative is every nameless admin
     * appearing as the same word.
     */
    public function getFilamentName(): string
    {
        if (filled($this->name)) {
            return (string) $this->name;
        }

        return Str::of((string) $this->email)->before('@')->whenEmpty(fn () => Str::of('Admin'))->toString();
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
