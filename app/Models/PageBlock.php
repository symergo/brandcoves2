<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Pages\PageCopy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One position in one region of one page, in one language.
 *
 * Holds no words. The words are `page_block_variants` rows, because a block that
 * can be said three ways is the whole reason this is not a `text` column: a
 * thousand pages opening with one identical sentence is a pattern visible in a
 * single sample.
 *
 * @property string $page
 * @property string $region
 * @property string $language
 * @property string $kind
 * @property int $position
 * @property list<string> $conditions
 * @property bool $enabled
 */
class PageBlock extends Model
{
    protected $guarded = [];

    public const HEADING = 'heading';

    public const PARAGRAPH = 'paragraph';

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'enabled' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * Flush on any write, from the model rather than from the screen.
     *
     * The edit can arrive from the Filament page, from `bc:import-content`, from
     * a seeder or from a tinker session. The model is the one place all four
     * pass through — which is exactly why the old `CopyBank` went stale after an
     * import: it was flushed by the admin page, and the importer is not one.
     *
     * The cascade delete of variants does **not** fire their model events. That
     * is covered, because deleting a block fires this hook.
     */
    protected static function booted(): void
    {
        static::saved(fn () => PageCopy::flush());
        static::deleted(fn () => PageCopy::flush());
    }

    /** @return HasMany<PageBlockVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(PageBlockVariant::class, 'block_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Blocks the renderer may consider.
     *
     * Only `enabled` — the conditions and the placeholder-availability rule are
     * evaluated per page, against facts a query cannot see.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeShown(Builder $query): void
    {
        $query->where('enabled', true);
    }

    public function isHeading(): bool
    {
        return $this->kind === self::HEADING;
    }

    /** The first words of this block, for a list label. */
    public function preview(): string
    {
        return (string) $this->variants->first()?->body;
    }
}
