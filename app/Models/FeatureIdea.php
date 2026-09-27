<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeatureStatus;
use App\Enums\ModerationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An idea on the contribute page's voting board.
 *
 * `title` and `body` hold every language on one row, keyed `nl`, `en`, `fr`,
 * `es`, because a vote is for the idea and not for one wording of it. See the
 * migration `2026_09_28_000900_feature_ideas_and_votes` and
 * docs/features/contribute.md.
 *
 * @property int $id
 * @property string|null $seed_key
 * @property array<string, string> $title
 * @property array<string, string> $body
 * @property string $language
 * @property FeatureStatus $status
 * @property ModerationStatus $moderation
 * @property string $source
 * @property int|null $suggested_by
 * @property string|null $market
 * @property int $sort
 * @property Carbon|null $decided_at
 */
class FeatureIdea extends Model
{
    /** Shipped in resources/content/feature-ideas.php, untouched since. */
    public const SOURCE_SEED = 'seed';

    /** Written or edited by a person in the admin. Never re-seeded over. */
    public const SOURCE_OWNER = 'owner';

    /** Suggested by a signed-in visitor on the page. */
    public const SOURCE_VISITOR = 'visitor';

    /** The languages an idea can be written in: the site's four. */
    public const LANGUAGES = ['nl', 'en', 'fr', 'es'];

    protected $fillable = [
        'seed_key',
        'title',
        'body',
        'language',
        'status',
        'moderation',
        'source',
        'suggested_by',
        'market',
        'sort',
        'decided_at',
    ];

    protected $attributes = [
        'body' => '{}',
        'status' => 'considering',
        'moderation' => 'pending',
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'body' => 'array',
            'status' => FeatureStatus::class,
            'moderation' => ModerationStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /** @return HasMany<FeatureVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(FeatureVote::class);
    }

    /** @return BelongsTo<User, $this> */
    public function suggester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_by');
    }

    /** @param Builder<FeatureIdea> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('moderation', ModerationStatus::Published->value);
    }

    /**
     * The text in one language, falling back to the language it was written
     * in, then to whichever language has any. A visitor's suggestion arrives
     * in their language only, and a French reader is better served by the
     * Dutch words than by an empty card.
     *
     * @param  'title'|'body'  $field
     */
    public function text(string $field, string $language): string
    {
        /** @var array<string, string|null> $values */
        $values = $this->{$field} ?? [];

        foreach ([$language, $this->language] as $candidate) {
            $value = trim((string) ($values[$candidate] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }
}
