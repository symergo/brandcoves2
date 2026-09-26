<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A split: one offer given an identity key of its own.
 *
 * Beats the key the offer was ingested with and any alias on that key, so an
 * offer taken out of a product stays out however that product is merged later.
 *
 * @property int $id
 * @property int $product_id
 * @property string $forced_key
 * @property string|null $reason
 * @property int|null $created_by
 */
class IdentityOverride extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
