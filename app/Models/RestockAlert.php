<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestockAlert extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => AlertState::class,
            'notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
