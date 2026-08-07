<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CollaboratorRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WishlistCollaborator extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['role' => CollaboratorRole::class];
    }

    /** @return BelongsTo<Wishlist, $this> */
    public function wishlist(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
