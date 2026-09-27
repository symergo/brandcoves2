<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Thumb;
use Illuminate\Database\Eloquent\Model;

/**
 * One visitor's thumb on one product, for the crowd signal. The visitor is a
 * one-way code (Owner::identityHash('gift-vote')), never an id. Only read as
 * counts over enough different voters; see App\Services\Gift\GiftFeedback.
 */
class GiftVote extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['vote' => Thumb::class];
    }
}
