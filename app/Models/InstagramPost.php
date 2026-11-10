<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Social\Instagram\InstagramPoster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Cove's post on GiftCoves' Instagram account, or the attempt at one.
 * Written only by {@see InstagramPoster}.
 *
 * @property int $daily_pick_set_id
 * @property string $market
 * @property string $status posted | failed
 * @property int $attempts
 * @property string|null $media_id
 * @property string|null $caption
 * @property string|null $error
 * @property Carbon|null $posted_at
 */
class InstagramPost extends Model
{
    public const POSTED = 'posted';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DailyPickSet, $this> */
    public function cove(): BelongsTo
    {
        return $this->belongsTo(DailyPickSet::class, 'daily_pick_set_id');
    }
}
