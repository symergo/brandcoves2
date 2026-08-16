<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\ModerationStatus;
use App\Enums\Vibe;
use Database\Factories\CommunityQuestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * "I need ideas for my sister, she is thirty and likes climbing."
 *
 * @property ModerationStatus $status
 * @property Market $market
 */
class CommunityQuestion extends Model
{
    /** @use HasFactory<CommunityQuestionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'status' => ModerationStatus::class,
            'published_at' => 'datetime',

            /*
             * Optional structure, in the Gift Finder's own vocabulary.
             *
             * `interests` holds `Interest` values and `vibe` a `Vibe`, so an
             * answerer's product search can be seeded straight from a question
             * and the two surfaces cannot drift into two ideas of what
             * "cooking" means. Both render through `label()`, so the structured
             * half of the board is localised for free.
             */
            'interests' => 'array',
            'values' => 'array',
            'vibe' => Vibe::class,
        ];
    }

    /**
     * The ticked fields, as labels in the reader's language.
     *
     * Rendered as chips beside the question. Built here rather than in the
     * controller because both the board and the question page want the same
     * list, and an enum value that no longer exists is skipped rather than
     * printed raw — a retired interest should quietly vanish from an old
     * question, not appear as `photography` in the middle of Dutch.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $tags = [];

        foreach ((array) $this->interests as $value) {
            if (($interest = Interest::tryFrom((string) $value)) !== null) {
                $tags[] = $interest->label();
            }
        }

        if ($this->vibe !== null) {
            $tags[] = $this->vibe->label();
        }

        foreach ((array) $this->values as $value) {
            $key = 'site.gift.values.'.$value;

            if (__($key) !== $key) {
                $tags[] = __($key);
            }
        }

        return $tags;
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Answers anybody may read.
     *
     * The default relation is the published one, exactly as `Wishlist::items()`
     * hides pending suggestions — every surface that renders "the answers"
     * should get the safe set without asking, and the one screen that wants the
     * queue says so explicitly.
     *
     * @return HasMany<CommunityAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(CommunityAnswer::class, 'question_id')
            ->where('status', ModerationStatus::Published->value)
            ->oldest('published_at');
    }

    /** Everything, including what is still waiting on a decision. */
    public function allAnswers(): HasMany
    {
        return $this->hasMany(CommunityAnswer::class, 'question_id');
    }

    /**
     * Decoration in the URL, identity in the id.
     *
     * Same rule as a product: the slug is regenerated from the current title on
     * every render, so retitling a question cannot strand the links people have
     * already shared — the controller redirects a stale slug rather than 404ing.
     */
    public function slug(): string
    {
        return Str::slug($this->title) ?: 'question';
    }

    /** @param Builder<$this> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ModerationStatus::Published->value);
    }

    /** @param Builder<$this> $query */
    public function scopeForMarket(Builder $query, Market $market): void
    {
        $query->where('market', $market->value);
    }

    /**
     * Publish, and stamp the date the CHECK constraint insists on.
     *
     * `community_questions_published_is_dated` refuses a row where `status` and
     * `published_at` disagree, so the two only ever move together.
     */
    public function publish(): void
    {
        $this->forceFill([
            'status' => ModerationStatus::Published,
            'published_at' => now(),
        ])->save();
    }

    public function refuse(?string $note = null): void
    {
        $this->forceFill([
            'status' => ModerationStatus::Rejected,
            'published_at' => null,
            'moderation_note' => $note,
        ])->save();
    }

    /** Recount published answers. Called by `CommunityAnswer`'s model events. */
    public function recountAnswers(): void
    {
        $this->forceFill([
            'answers_count' => $this->allAnswers()
                ->where('status', ModerationStatus::Published->value)
                ->count(),
        ])->saveQuietly();
    }

    /**
     * May this person see it at all?
     *
     * A pending or rejected question is visible to its author and to nobody
     * else. Showing the author their own held post is what stops the feature
     * looking broken — they pressed a button and something has to be there —
     * and it is not a disclosure, because it is their own writing.
     */
    public function isVisibleTo(?User $viewer): bool
    {
        return $this->status->isPublished()
            || ($viewer !== null && ($viewer->id === $this->user_id || $viewer->is_admin));
    }
}
