<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app notification inbox: price drops, restocks, list activity.
 *
 * Distinct from Laravel's own notifications table — these are user-facing
 * records with their own read state and lifecycle, not queued deliveries.
 */
class Notification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<$this> $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }
}
