<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only interaction log: searches, click-outs, gift swaps, shortlists,
 * rejections, reactions.
 *
 * Nothing reads this yet, and that is fine. It exists from day one because the
 * learning loop it enables — what *this* audience finds surprising — needs
 * months of history to be worth anything, and history cannot be backfilled.
 */
class Event extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
