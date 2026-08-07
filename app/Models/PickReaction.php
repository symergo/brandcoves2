<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Reaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickReaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reaction' => Reaction::class];
    }

    /** @return BelongsTo<DailyPick, $this> */
    public function pick(): BelongsTo
    {
        return $this->belongsTo(DailyPick::class, 'pick_id');
    }
}
