<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Source;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on a Cove's curated shortlist.
 *
 * The list is chosen before the article is written, and the article is written
 * around the list — so a row here is two things at once: a product that will
 * appear in the edition, and a brief to whoever writes about it.
 *
 * Two shapes, because the sources differ in what may be kept:
 *
 *   `group_id` — a product in our own catalogue. The ordinary case, including
 *   anything found live on bol, which is folded into the catalogue by the same
 *   search that surfaced it.
 *
 *   `source` + `external_id` — a source whose catalogue may not be mirrored.
 *   The decision is stored and nothing a visitor reads; title, price, image and
 *   availability are re-fetched live at render. Invariant 6.
 *
 * A CHECK constraint enforces that one of the two is present, so a row can
 * never be an item that refers to nothing.
 */
class CovePlanItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => Source::class,
            'rank' => 'integer',
        ];
    }

    /**
     * The sentence a reader sees under this product's card.
     *
     * Distinct from `note`, which is why the product was chosen and is read by
     * the writer alone. Both exist because both are written, by different people
     * at different moments: the curator says "the only one with a real grinder"
     * while choosing, and the article says something a reader can enjoy.
     *
     * Null for the ordinary case — the builder writes the card copy at build
     * time and never stores it here. Filled when a person or an external author
     * wrote it, and then it wins: see `EditionBuilder::itemCopy()`.
     */
    public function hasAuthoredCopy(): bool
    {
        return filled($this->copy);
    }

    /** @return BelongsTo<CovePlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(CovePlan::class, 'plan_id');
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function curator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * A pick whose source forbids mirroring, held as a decision rather than a row.
     *
     * Today that means Amazon and only Amazon. Asked of the source rather than
     * of the columns, so a second such source needs no change here.
     */
    public function isLiveOnly(): bool
    {
        return $this->group_id === null;
    }

    /** @param Builder<$this> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('rank')->orderBy('id');
    }
}
