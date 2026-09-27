<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed-in person's vote for one idea on the contribute page.
 *
 * Unique on (idea, user): pressing again takes the vote back rather than
 * adding a second one. Deleted with the account and with the idea.
 *
 * @property int $id
 * @property int $feature_idea_id
 * @property int $user_id
 */
class FeatureVote extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['feature_idea_id', 'user_id'];

    /** @return BelongsTo<FeatureIdea, $this> */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(FeatureIdea::class, 'feature_idea_id');
    }
}
