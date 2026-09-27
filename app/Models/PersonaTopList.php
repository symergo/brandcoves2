<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One persona's top 10 for one week: product ids in order, no counts.
 * See App\Services\Gift\PersonaTopTen.
 *
 * @property int $set_id
 * @property Carbon $week
 * @property list<int> $group_ids
 */
class PersonaTopList extends Model
{
    protected $fillable = ['set_id', 'week', 'group_ids'];

    protected function casts(): array
    {
        return [
            'week' => 'date',
            'group_ids' => 'array',
        ];
    }

    /** @return BelongsTo<DailyPickSet, $this> */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(DailyPickSet::class, 'set_id');
    }
}
