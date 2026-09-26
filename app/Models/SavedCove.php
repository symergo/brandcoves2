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

    /**
     * The editorial Cove, when this bookmark is on one. Exactly one of this and
     * {@see list()} is set (the `saved_coves_one_target` CHECK).
     *
     * @return BelongsTo<DailyPickSet, $this>
     */
    public function cove(): BelongsTo
    {
        return $this->belongsTo(DailyPickSet::class, 'set_id');
    }

    /**
     * The Community Cove, when this bookmark is on a list somebody published.
     * See docs/features/community-coves.md.
     *
     * @return BelongsTo<Wishlist, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class, 'wishlist_id');
    }
}
