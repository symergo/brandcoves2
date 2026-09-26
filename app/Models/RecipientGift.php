<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Gift\GiftHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody noted they gave one of their saved people.
 *
 * Typed on the person's page, or an item from a list about them marked "I gave
 * this". Claims are not stored here; {@see GiftHistory}
 * reads them live, by the claimer's own hash. See docs/features/gift-history.md.
 *
 * @property int $id
 * @property string $recipient_id
 * @property int|null $group_id
 * @property int|null $wishlist_item_id
 * @property string $title
 * @property int $given_year
 */
class RecipientGift extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'wishlist_item_id' => 'integer',
            'given_year' => 'integer',
        ];
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }
}
