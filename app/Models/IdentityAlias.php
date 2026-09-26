<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Model;

/**
 * A merge, kept where the grouper can see it.
 *
 * Offers whose identity key is `from_key` are grouped as if it were `to_key`.
 * Followed one hop only: `GroupMerger` rewrites every alias that pointed at a
 * product it merges away, so a chain never forms.
 *
 * @property int $id
 * @property Market $market
 * @property string $from_key
 * @property string $to_key
 * @property string|null $reason
 * @property int|null $created_by
 */
class IdentityAlias extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['market' => Market::class];
    }
}
