<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\MatchRule;
use App\Enums\MatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two products a rule thinks are one, waiting for a person to say so.
 *
 * Stored with `group_a < group_b`, unique on the pair: whichever rule finds a
 * pair first owns it, and a rejected pair is never proposed again.
 *
 * @property int $id
 * @property Market $market
 * @property int $group_a
 * @property int $group_b
 * @property MatchRule $rule
 * @property float $score
 * @property string|null $evidence
 * @property MatchStatus $status
 */
class MatchCandidate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'rule' => MatchRule::class,
            'status' => MatchStatus::class,
            'score' => 'float',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function groupA(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_a');
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function groupB(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_b');
    }
}
