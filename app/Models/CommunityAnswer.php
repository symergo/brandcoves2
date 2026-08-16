<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModerationStatus;
use Database\Factories\CommunityAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's answer, with the products they would actually buy.
 *
 * @property ModerationStatus $status
 */
class CommunityAnswer extends Model
{
    /** @use HasFactory<CommunityAnswerFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ModerationStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * Keep `community_questions.answers_count` honest.
     *
     * The count is denormalised because the board renders it on every row, and
     * it counts **published** answers only — a question showing "3 answers" and
     * then displaying none because all three are held is worse than showing
     * nothing. Maintained here rather than at the call sites: publishing happens
     * from the triage job and from the admin, and a second place to increment is
     * a second place to forget.
     */
    protected static function booted(): void
    {
        static::saved(function (self $answer): void {
            /*
             * Recount whenever this save moved `status`, and only then — an
             * edit to the body must not touch the counter.
             *
             * `wasChanged()` and not `wasRecentlyCreated`: that flag stays true
             * for the rest of the instance's life, so a model created and then
             * published and then refused took the "was it just created?" branch
             * all three times and stopped counting after the first. The counter
             * read 1 for a question with no published answers on it.
             */
            if ($answer->wasChanged('status')) {
                $answer->question?->recountAnswers();
            }
        });

        static::deleted(fn (self $answer) => $answer->question?->recountAnswers());
    }

    /** @return BelongsTo<CommunityQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(CommunityQuestion::class, 'question_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<CommunityAnswerPick, $this> */
    public function picks(): HasMany
    {
        return $this->hasMany(CommunityAnswerPick::class, 'answer_id')->orderBy('position');
    }

    /**
     * The products themselves, in the order the answerer chose.
     *
     * @return BelongsToMany<ProductGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ProductGroup::class, 'community_answer_picks', 'answer_id', 'group_id')
            ->withPivot('position')
            ->orderBy('community_answer_picks.position');
    }

    /**
     * Publish, and stamp the date the CHECK constraint insists on.
     *
     * `status` and `published_at` have to move together —
     * `community_answers_published_is_dated` refuses a row where they disagree
     * — so nothing sets one of them alone.
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

    /** Its author sees their own held answer; nobody else does. */
    public function isVisibleTo(?User $viewer): bool
    {
        return $this->status->isPublished()
            || ($viewer !== null && ($viewer->id === $this->user_id || $viewer->is_admin));
    }
}
