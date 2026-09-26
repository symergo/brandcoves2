<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's play through a TasteInvite: the choices as the page sent them
 * (product ids and what was pressed), and nothing about who played.
 *
 * `participant_hash` is the player's cookie identity hashed with the invite's
 * id as the purpose (Owner::identityHash), so a second play from the same
 * browser replaces the first, and the value matches nothing in any other
 * table. It is `$hidden` so it can never reach a page by accident.
 */
class TasteRun extends Model
{
    protected $guarded = [];

    protected $hidden = ['participant_hash'];

    protected function casts(): array
    {
        return [
            'choices' => 'array',
            'answered' => 'integer',
        ];
    }

    /** @return BelongsTo<TasteInvite, $this> */
    public function invite(): BelongsTo
    {
        return $this->belongsTo(TasteInvite::class, 'taste_invite_id');
    }
}
