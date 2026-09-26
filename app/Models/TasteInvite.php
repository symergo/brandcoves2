<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "help me find out what Anna likes" link: This or that, played by several
 * people about one of the giver's people, their answers combined.
 *
 * The token is the whole permission to play, like a list's share token. It
 * grants playing and nothing else: the page it opens shows the person's name
 * and never anything the giver wrote about them. See
 * docs/features/taste-together.md.
 */
class TasteInvite extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'revoked_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /** @return HasMany<TasteRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(TasteRun::class);
    }

    public function isOpen(): bool
    {
        return $this->revoked_at === null;
    }
}
