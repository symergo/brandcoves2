<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Pages\PageCopy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way of saying what a block says.
 *
 * The renderer asks a block for its text and never for a specific variant: which
 * one a given page gets is the rotation's business.
 *
 * @property int $block_id
 * @property string $body
 * @property int $weight
 * @property bool $enabled
 */
class PageBlockVariant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'weight' => 'integer',
        ];
    }

    /** @see PageBlock::booted() for why this lives on the model. */
    protected static function booted(): void
    {
        static::saved(fn () => PageCopy::flush());
        static::deleted(fn () => PageCopy::flush());
    }

    /** @return BelongsTo<PageBlock, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(PageBlock::class, 'block_id');
    }

    /**
     * Variants the rotation may draw from.
     *
     * Weight zero is excluded here rather than treated as a zero-probability
     * draw: it is the soft retirement, and a retired phrasing should not be able
     * to come back because somebody changed how weighting works.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDrawable(Builder $query): void
    {
        $query->where('enabled', true)->where('weight', '>', 0);
    }
}
