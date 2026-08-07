<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate guide topic, clustered from real search queries and ranked.
 *
 * Surfaced in admin so a human queues or rejects one before anything is
 * generated — an automated pipeline that publishes unreviewed pages is how a
 * site fills up with thin content.
 */
class GuideTopic extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'member_queries' => 'array',
        ];
    }

    /** @return BelongsTo<Guide, $this> */
    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    }

    /**
     * A topic is only worth writing if we can actually fill it. High search
     * volume with no matching products is a catalogue gap, not a guide.
     */
    public function isViable(): bool
    {
        return $this->available_products >= (int) config('brandcoves.guides.min_products');
    }
}
