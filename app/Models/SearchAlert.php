<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertState;
use App\Enums\Market;
use App\Support\SearchUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A watched search. See docs/features/search-alerts.md.
 */
class SearchAlert extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'state' => AlertState::class,
            'seen_group_ids' => 'array',
            'last_checked_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /**
     * The stored form of a term: trimmed, lower-cased, one space between words.
     *
     * The unique index is on this, so "Lego" and "lego " are one watch rather
     * than two rows that fire twice for the same product.
     */
    public static function normalise(string $term): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $term)));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Where the person lands from the notification: the search, with its ceiling. */
    public function searchPath(): string
    {
        $params = [];

        if ($this->max_price !== null) {
            $params['max'] = number_format($this->max_price / 100, 2, '.', '');
        }

        return SearchUrl::for($this->market, $this->term, $params);
    }
}
