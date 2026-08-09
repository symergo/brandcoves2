<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecipientStatus;
use App\Enums\TasteSource;
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
            'status' => RecipientStatus::class,
            'taste_source' => TasteSource::class,
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

    /**
     * The account this recipient *is*, once somebody claimed the link.
     *
     * @return BelongsTo<User, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isLinked(): bool
    {
        return $this->user_id !== null && $this->status->isLinked();
    }

    /**
     * Write taste, refusing to let a guess overwrite the person's own answer.
     *
     * The destructive direction is always the wrong one: if they have told us
     * what they like, my guess is simply worse evidence, and silently replacing
     * theirs with mine is the one outcome nobody would choose deliberately.
     *
     * @param  array<string, mixed>  $taste
     */
    public function describeTaste(array $taste, TasteSource $source): bool
    {
        if ($taste === []) {
            return false;
        }

        if ($this->taste_source instanceof TasteSource && $this->taste_source->outranks($source)) {
            return false;
        }

        $this->fill([...$taste, 'taste_source' => $source])->save();

        return true;
    }
}
