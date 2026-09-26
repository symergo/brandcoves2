<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IdeaPriceBand;
use App\Enums\Market;
use App\Enums\OfflineIdeaStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A gift idea nobody sells here, learned from what people typed onto their
 * own lists by hand ("a cooking workshop", "a spa day").
 *
 * Proposed by the nightly count once enough different people wrote it
 * (`giftcoves.offline_ideas.min_owners`), and shown only after a person
 * approved it and wrote the wording. `key`, `sample_title` and `owners` are
 * for the reviewer; a visitor only ever sees `id` and `title`. See
 * docs/features/offline-ideas.md.
 *
 * @property int $id
 * @property Market $market
 * @property string $key
 * @property string|null $sample_title
 * @property string|null $title
 * @property OfflineIdeaStatus $status
 * @property list<string> $tags
 * @property IdeaPriceBand|null $price_band
 * @property int $owners
 */
class OfflineIdea extends Model
{
    protected $guarded = [];

    /**
     * Never serialised, even by accident: these say how many people wrote it
     * and how one of them spelled it.
     *
     * @var list<string>
     */
    protected $hidden = ['key', 'sample_title', 'owners', 'decided_by'];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'status' => OfflineIdeaStatus::class,
            'price_band' => IdeaPriceBand::class,
            'tags' => 'array',
            'owners' => 'integer',
            'last_seen_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<OfflineIdea>  $query
     * @return Builder<OfflineIdea>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', OfflineIdeaStatus::Approved->value);
    }
}
