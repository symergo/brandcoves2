<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Seo\CopySlots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One editable variant of one sentence on a search or brand page.
 *
 * The code asks `CopyBank` for a slot and never for a row: which variant a given
 * page gets is the rotation's business, not the caller's.
 *
 * @property string $surface
 * @property string $slot
 * @property string $language
 * @property string $body
 * @property int $weight
 * @property bool $enabled
 */
class CopyTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'weight' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Variants the rotation may draw from.
     *
     * Weight zero is excluded here rather than treated as a zero-probability
     * draw: it is the soft retirement, and a retired line should not be able to
     * come back because someone changed how the weighting works.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDrawable(Builder $query): void
    {
        $query->where('enabled', true)->where('weight', '>', 0);
    }

    /** Human label for the slot, from the registry rather than the row. */
    public function slotLabel(): string
    {
        return CopySlots::find($this->surface, $this->slot)['label'] ?? $this->slot;
    }

    /**
     * A slot the code no longer asks for.
     *
     * Shown in admin rather than hidden: a variant nobody will ever see is worth
     * knowing about, and deleting it automatically would throw away copy someone
     * wrote during a refactor that renamed the slot.
     */
    public function isOrphaned(): bool
    {
        return CopySlots::find($this->surface, $this->slot) === null;
    }

    /** @return list<string> */
    public function disallowedPlaceholders(): array
    {
        return CopySlots::disallowedIn($this->surface, $this->slot, $this->body);
    }
}
