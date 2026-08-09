<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A quiz over somebody's list.
 *
 * The rounds are stored, not regenerated: two people comparing scores have to
 * have answered the same questions, which is what makes a posted result a
 * conversation rather than a broadcast.
 */
class ListQuiz extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $quiz): void {
            $quiz->share_token ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['rounds' => 'array', 'market' => Market::class];
    }

    /** @return BelongsTo<Wishlist, $this> */
    public function wishlist(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class);
    }

    /** @return HasMany<ListQuizAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(ListQuizAttempt::class, 'quiz_id');
    }

    /**
     * The questions, with the answers stripped.
     *
     * The payload a player receives must not contain the thing they are being
     * asked to guess. Obvious, and exactly the sort of thing that survives into
     * production because the page looks right.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(): array
    {
        return array_map(
            fn (array $round) => ['title' => null, 'options' => $round['options']],
            (array) $this->rounds,
        );
    }

    /** @return list<int> */
    public function answers(): array
    {
        return array_map(fn (array $round) => (int) $round['answer'], (array) $this->rounds);
    }
}
