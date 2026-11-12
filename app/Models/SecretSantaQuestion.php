<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A question from a Secret Friend to the person they drew, asked without a name.
 *
 * `giftee_member_id` is plain: the giftee has to find their questions. Who
 * asked is encrypted and hidden, exactly like the assignment, because asker
 * plus giftee *is* the assignment.
 *
 * @property int $id
 * @property int $giftee_member_id
 * @property string $asker_member_id
 * @property string $question
 * @property string|null $answer
 * @property Carbon|null $answered_at
 */
class SecretSantaQuestion extends Model
{
    protected $guarded = [];

    protected $hidden = ['asker_member_id'];

    /** How many one Santa may ask in one group: a chat, not a channel to pester through. */
    public const MAX_PER_ASKER = 8;

    protected function casts(): array
    {
        return [
            'asker_member_id' => 'encrypted',
            'answered_at' => 'datetime',
        ];
    }

    public function giftee(): BelongsTo
    {
        return $this->belongsTo(SecretSantaMember::class, 'giftee_member_id');
    }
}
