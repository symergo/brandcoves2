<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RecipientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A person you might buy for — the Gift Whisperer's input.
 *
 * Gifting is anti-search: a shopper knows the product, a gift-giver only knows
 * the recipient. This model is that knowledge, made queryable.
 */
class Recipient extends Model
{
    /** @use HasFactory<RecipientFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = [];

    /** Personal notes about a real person. Never leaked to a shared list view. */
    protected $hidden = ['notes'];

    protected static function booted(): void
    {
        // NOT NULL, and the token is what lets a recipient fill in their own
        // tastes without seeing what has been picked for them. Generated here so
        // no code path can create a recipient without one.
        static::creating(function (self $recipient): void {
            $recipient->share_token ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'interests' => 'array',
            'values' => 'array',
            'avoid' => 'array',
            'birthday' => 'date',
        ];
    }

    /** @return HasMany<Wishlist, $this> */
    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
