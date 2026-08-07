<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Model;

/**
 * One reaction to one discovery result.
 *
 * The training data for per-mode weight tuning. Append-only; nothing reads it
 * yet, and that is fine — a learning loop needs months of history and history
 * cannot be backfilled.
 */
class DiscoveryReaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'input' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
