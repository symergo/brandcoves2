<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One player's round at one edition's price guess.
 *
 * `guesses` is $hidden: the raw numbers are only ever needed server-side, and
 * the client is given bands. Shipping the guesses would let a determined player
 * reconstruct the answer from their own history far more precisely than the
 * bands allow, which is the difference between a puzzle and a calculator.
 */
class ChallengeAttempt extends Model
{
    protected $guarded = [];

    protected $hidden = ['guesses'];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'played_on' => 'date',
            'guesses' => 'array',
            'bands' => 'array',
            'solved' => 'boolean',
        ];
    }

    /** @return BelongsTo<DailyPickSet, $this> */
    public function edition(): BelongsTo
    {
        return $this->belongsTo(DailyPickSet::class, 'set_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
