<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AskAudience;
use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\ModerationStatus;
use App\Enums\Vibe;
use App\Jobs\SendQuestionToPeople;
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
 * @property AskAudience $audience
 * @property string|null $share_token
 */
class CommunityQuestion extends Model
{
    /** @use HasFactory<CommunityQuestionFactory> */
    use HasFactory;

    protected $guarded = [];

    /*
     * The code in a people question's link is the whole of the permission to
     * open it (see `peopleUrl()`), so it never rides along when a model is
     * serialised by accident.
     */
    protected $hidden = ['share_token'];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'status' => ModerationStatus::class,
            'audience' => AskAudience::class,
            'published_at' => 'datetime',
            'people_notified_at' => 'datetime',

            /*
             * Optional structure, in Find a gift's own vocabulary.
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
     * The list the question was asked from, if any.
     *
     * The asker's own: it is read only to give *them* a "save to this list"
     * button on the answers, and never shown to anybody else.
     *
     * @return BelongsTo<Wishlist, $this>
     */
    public function wishlist(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class, 'wishlist_id');
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

    /**
     * On the public board: published, and asked of the community.
     *
     * Every caller of this scope lists questions for strangers (the board,
     * Discover, the sitemap), so a people question is excluded here, once,
     * rather than at each of them: the next listing somebody writes gets the
     * safe set without having to know people questions exist. A people
     * question is found by its link code (`ask/p/{token}`) or as one of your
     * friends' (`PeopleQuestions`), never through this.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ModerationStatus::Published->value)
            ->where('audience', AskAudience::Public->value);
    }

    /** Only for your people: friends and whoever holds the link. */
    public function isForPeople(): bool
    {
        return $this->audience === AskAudience::People;
    }

    /**
     * Where the question lives, in the market it was asked in.
     *
     * A board question by id and slug; a people question by its link code,
     * because an id is sequential and "only your people" must not mean
     * "anybody who counts". Relative to the market: callers wrap it in
     * `CurrentMarket::url()` or put `/{market}/` in front.
     */
    public function path(): string
    {
        return $this->isForPeople()
            ? "ask/p/{$this->share_token}"
            : "ask/{$this->id}/{$this->slug()}";
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

        /*
         * Now, and only now, the asker's people may hear about it: a question
         * that is not on the board must not travel by another route. (A
         * people question is created published by `PeopleQuestions::ask()`,
         * which queues the same job; an admin publishing one again after a
         * refusal sends nothing twice, see `people_notified_at`.) Queued
         * after the commit, so the job never reads the row before it is
         * published. Sent once however often this runs (`people_notified_at`).
         */
        SendQuestionToPeople::dispatch($this->id)->afterCommit();
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
