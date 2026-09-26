<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's bookmark on a published Cove. See App\Services\Cove\SavedCoves
 * and docs/features/saved-coves.md.
 */
class SavedCove extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DailyPickSet, $this> */
    public function cove(): BelongsTo
    {
        return $this->belongsTo(DailyPickSet::class, 'set_id');
    }
}
