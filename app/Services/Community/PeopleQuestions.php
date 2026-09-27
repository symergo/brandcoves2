<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enums\AskAudience;
use App\Enums\Market;
use App\Enums\ModerationStatus;
use App\Jobs\SendQuestionToPeople;
use App\Models\CommunityQuestion;
use App\Models\Friendship;
use App\Models\User;
use App\Support\ShareCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A question for your people only (owner, 2026-09-27: "ask the GiftCoves
 * community, ask your people on GiftCoves (provide a share link after
 * posting)").
 *
 * ## Who sees it
 *
 * The asker's friends on GiftCoves, who are told at once, and anybody holding
 * the link, which the asker is handed the moment they post. That is exactly
 * the audience of a shared list, and it is treated the same way: the code in
 * the link is the permission (`ShareCode`, 50 bits), the id is never an
 * address for it, and nothing a stranger can reach lists it (the board, the
 * sitemap and Discover all go through `CommunityQuestion::published()`, which
 * leaves people questions out).
 *
 * ## Why it is not read first
 *
 * The board is moderated because it publishes a stranger's writing on an
 * indexable page of ours (ask-others.md). A people question is not published
 * to anybody: it goes to people the asker chose, the way a list, a
 * suggestion or an invitation does, none of which is read first. Holding it
 * would also break the one thing the owner asked for, a link to send straight
 * after posting: a link to a page that says "we are still reading this" is
 * not something anybody sends. So it is created visible, `published` in the
 * status column (which is what makes it answerable and keeps the
 * status-and-date CHECK true), and the report path stays: an admin can still
 * refuse it, after which the link is a 404 to everybody but the asker.
 *
 * Answers on it are still read first, by the same job as on the board: they
 * are written by whoever holds the link, which may be further than the asker
 * meant, and a held answer costs its writer a short wait and nothing else.
 */
class PeopleQuestions
{
    /** Friends' questions shown on the board page, newest first. */
    public const FROM_FRIENDS = 6;

    /**
     * Create it, visible at once to its people, and tell them.
     *
     * @param  array<string, mixed>  $attributes  the same columns the board's form fills
     */
    public function ask(array $attributes): CommunityQuestion
    {
        return DB::transaction(function () use ($attributes): CommunityQuestion {
            $question = CommunityQuestion::create([
                ...$attributes,
                'audience' => AskAudience::People,
                'share_token' => $this->freshToken(),
                'status' => ModerationStatus::Published,
                'published_at' => now(),
            ]);

            /*
             * The asker's friends hear about it now, not "once it is on the
             * board": it never will be. Same job, same receiver switches and
             * the same once-only claim as a board question; see
             * QuestionToPeople for what differs. After the commit, so the job
             * never reads a row that is not there yet.
             */
            SendQuestionToPeople::dispatch($question->id)->afterCommit();

            return $question;
        });
    }

    /** The question behind a link code, in this market, or null. */
    public function find(Market $market, string $token): ?CommunityQuestion
    {
        return CommunityQuestion::query()
            ->forMarket($market)
            ->where('audience', AskAudience::People->value)
            ->where('share_token', $token)
            ->with('author')
            ->first();
    }

    /**
     * May this person open it, holding the link?
     *
     * Holding the link is the permission, as on a shared list, so a
     * published people question opens for anybody who has it, signed in or
     * not. One an admin refused opens for its asker (to see what happened)
     * and for admins only.
     */
    public function mayOpen(CommunityQuestion $question, ?User $viewer): bool
    {
        return $question->isForPeople() && $question->isVisibleTo($viewer);
    }

    /**
     * Your friends' people questions, for the board page.
     *
     * The notification is how a friend first hears; this is how they find it
     * again after they dismissed it, or when the one-a-day limit kept the
     * second question of an evening out of their inbox. Only questions of
     * people you are friends with *now*: removing a friend takes their
     * questions off your page at once, as it takes their lists.
     *
     * @return Collection<int, CommunityQuestion>
     */
    public function fromFriends(User $viewer, Market $market): Collection
    {
        $friendIds = Friendship::query()
            ->where('user_id', $viewer->id)
            ->pluck('friend_id');

        if ($friendIds->isEmpty()) {
            return collect();
        }

        return CommunityQuestion::query()
            ->forMarket($market)
            ->where('audience', AskAudience::People->value)
            ->where('status', ModerationStatus::Published->value)
            ->whereIn('user_id', $friendIds)
            ->with('author')
            ->orderByDesc('published_at')
            ->limit(self::FROM_FRIENDS)
            ->get();
    }

    /** How many friends a people question would reach, for the form's hint. */
    public function friendCount(User $asker): int
    {
        return Friendship::query()->where('user_id', $asker->id)->count();
    }

    /**
     * A code no other question has.
     *
     * At 50 bits a collision is vanishingly rare; the loop is there so that
     * one costs a retry rather than a 500 on the unique index.
     */
    private function freshToken(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = ShareCode::make();

            if (! CommunityQuestion::query()->where('share_token', $token)->exists()) {
                return $token;
            }
        }

        throw new RuntimeException('Could not mint a unique link code for a question.');
    }
}
