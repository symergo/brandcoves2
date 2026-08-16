<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product an answer points at.
 *
 * A row, not a link in the prose. The difference is the whole reason answers
 * are worth having here: a pick is a `product_groups` id we already hold, so it
 * renders as an ordinary product card with a live price for the right market,
 * and every outbound click leaves through `/go/{offer}` where the scheme is
 * checked (invariant #5). There is nowhere for a stranger to paste a URL,
 * because the field does not exist.
 */
class CommunityAnswerPick extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<CommunityAnswer, $this> */
    public function answer(): BelongsTo
    {
        return $this->belongsTo(CommunityAnswer::class, 'answer_id');
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }
}
