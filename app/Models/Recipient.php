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

    /**
     * The year a day-and-month birthday is stored under.
     *
     * `birthday` is a `date` column and every reader matches on **month and day
     * only** — `SendOccasionReminders` extracts both and ignores the year,
     * because a birthday recurs and the stored year does not. So a birthday
     * given as "14 June" needs *some* year to be a date at all, and which one it
     * is must never leak into a sentence.
     *
     * 2000, and the choice is load-bearing: it is a leap year. Under 2001 a
     * person born on 29 February cannot be stored at all — `Carbon` rolls it to
     * 1 March, silently, and their reminder arrives on the wrong day forever.
     *
     * A real birth year, when somebody supplies one, is welcome and unaffected:
     * this is only the placeholder for the day-and-month form the UI asks for,
     * because a year is a piece of personal data with no use here.
     */
    public const BIRTHDAY_YEAR = 2000;

    /**
     * A day and a month as a storable date, or null.
     *
     * Returns null unless both halves are present and the pair is a real date —
     * 31 February is not one, and accepting it would store 3 March and remind
     * somebody on a day nobody named.
     */
    public static function birthdayFrom(?int $day, ?int $month): ?string
    {
        if ($day === null || $month === null) {
            return null;
        }

        if (! checkdate($month, $day, self::BIRTHDAY_YEAR)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', self::BIRTHDAY_YEAR, $month, $day);
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
