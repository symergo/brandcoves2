<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Thumb;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A thumb the owner gave an idea for one of their saved people. Read only for
 * that person, by App\Services\Gift\GiftFeedback; goes with the person.
 */
class RecipientFeedback extends Model
{
    protected $table = 'recipient_feedback';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['vote' => Thumb::class];
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
