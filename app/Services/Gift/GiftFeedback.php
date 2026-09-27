<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use App\Enums\RecipientType;
use App\Enums\Thumb;
use App\Models\GiftVote;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\RecipientFeedback;
use App\Support\Owner;
use Illuminate\Support\Facades\DB;

/**
 * Thumbs up and down on Find a gift's ideas: storing them, and reading them
 * back for the engine. See docs/features/find-a-gift.md, "Thumbs up, thumbs
 * down".
 *
 * Two readers, two tables:
 *
 * - **For one saved person** (`recipient_feedback`): what the owner said
 *   about ideas for that person. Read only when the brief is about that
 *   person, and only ever reached through a person the controller already
 *   scoped to the owner, so another owner's people never see it. It does not
 *   touch the person's interests or avoid list: a thumb is its own signal,
 *   and rewriting what the owner typed from one click would be the site
 *   deciding for them.
 * - **For everybody** (`gift_votes`): one vote per visitor per product,
 *   counted only once enough different people voted ({@see CrowdVotes}).
 *
 * No AI anywhere (invariant 1): an upsert and a grouped count.
 */
class GiftFeedback
{
    /** Purpose for Owner::identityHash, so this code joins nothing else. */
    private const PURPOSE = 'gift-vote';

    /**
     * Record a thumb, or take one back (`$vote` null).
     *
     * The crowd vote is written whatever the path; the person's is written
     * only when a saved person of the owner's is given. `$relationship` is the
     * brief's "who is it for" when there is no saved person.
     */
    public function record(
        Owner $owner,
        int $groupId,
        ?Thumb $vote,
        ?Recipient $recipient,
        ?string $relationship,
        Market $market,
    ): void {
        DB::transaction(function () use ($owner, $groupId, $vote, $recipient, $relationship, $market): void {
            if ($recipient !== null) {
                $this->recordForPerson($recipient, $groupId, $vote);
            }

            $hash = $owner->identityHash(self::PURPOSE);

            if ($hash === null) {
                return;
            }

            if ($vote === null) {
                GiftVote::query()->where('group_id', $groupId)->where('voter_hash', $hash)->delete();

                return;
            }

            GiftVote::query()->upsert(
                [[
                    'group_id' => $groupId,
                    'voter_hash' => $hash,
                    'relationship' => $this->context($recipient?->relationship ?? $relationship, $market)?->value,
                    'vote' => $vote->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['group_id', 'voter_hash'],
                ['relationship', 'vote', 'updated_at'],
            );
        });
    }

    private function recordForPerson(Recipient $recipient, int $groupId, ?Thumb $vote): void
    {
        if ($vote === null) {
            RecipientFeedback::query()->where('recipient_id', $recipient->id)->where('group_id', $groupId)->delete();

            return;
        }

        RecipientFeedback::query()->upsert(
            [[
                'recipient_id' => $recipient->id,
                'group_id' => $groupId,
                'vote' => $vote->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['recipient_id', 'group_id'],
            ['vote', 'updated_at'],
        );
    }

    /**
     * The thumbs already given to these cards, to draw them pressed: the
     * person's when there is one, otherwise this visitor's own crowd votes.
     *
     * @param  list<int>  $groupIds
     * @return array<int, string> group id => 'up' | 'down'
     */
    public function votesOn(Owner $owner, ?Recipient $recipient, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        if ($recipient !== null) {
            return RecipientFeedback::query()
                ->where('recipient_id', $recipient->id)
                ->whereIn('group_id', $groupIds)
                ->pluck('vote', 'group_id')
                ->map(fn ($vote) => $vote instanceof Thumb ? $vote->value : (string) $vote)
                ->all();
        }

        $hash = $owner->identityHash(self::PURPOSE);

        if ($hash === null) {
            return [];
        }

        return GiftVote::query()
            ->where('voter_hash', $hash)
            ->whereIn('group_id', $groupIds)
            ->pluck('vote', 'group_id')
            ->map(fn ($vote) => $vote instanceof Thumb ? $vote->value : (string) $vote)
            ->all();
    }

    /**
     * What the owner's thumbs said about ideas for this person.
     *
     * The id comes from a brief the controller built from a person it had
     * already scoped to the owner (TasteBrief::aboutRecipient), never from the
     * request directly.
     */
    public function forRecipient(string $recipientId): PersonFeedback
    {
        $rows = RecipientFeedback::query()
            ->where('recipient_id', $recipientId)
            // The newest first, and a ceiling: a person with hundreds of
            // thumbs is described well enough by the latest two hundred, and
            // the request stays one small read.
            ->latest('updated_at')
            ->limit(200)
            ->get(['group_id', 'vote']);

        if ($rows->isEmpty()) {
            return PersonFeedback::none();
        }

        $groups = ProductGroup::query()
            ->whereIn('id', $rows->pluck('group_id'))
            ->get(['id', 'category', 'brand', 'gift_tags', 'crowd_tags'])
            ->keyBy('id');

        $liked = [];
        $disliked = [];

        foreach ($rows as $row) {
            $group = $groups->get($row->group_id);

            if ($group === null) {
                continue;
            }

            if ($row->vote === Thumb::Up) {
                $liked[] = $group;
            } else {
                $disliked[] = $group;
            }
        }

        return PersonFeedback::fromGroups($liked, $disliked);
    }

    /**
     * The crowd's verdict on these candidates, for this kind of person.
     *
     * One grouped read over at most a few hundred ids, by the unique index's
     * leading column. `HAVING` drops anything under the threshold before it
     * leaves the database, and {@see CrowdVotes} checks it again.
     *
     * @param  list<int>  $groupIds
     * @return array<int, float> group id => -1..1, only products that count
     */
    public function crowd(array $groupIds, ?string $relationship, Market $market): array
    {
        if ($groupIds === []) {
            return [];
        }

        $min = self::minVoters();
        $context = $this->context($relationship, $market)?->value;

        $rows = DB::select(
            <<<'SQL'
            SELECT group_id,
                   count(*) FILTER (WHERE relationship = ?) AS context_voters,
                   count(*) FILTER (WHERE relationship = ? AND vote = 'up') AS context_up,
                   count(*) AS all_voters,
                   count(*) FILTER (WHERE vote = 'up') AS all_up
            FROM gift_votes
            WHERE group_id = ANY(?::bigint[])
            GROUP BY group_id
            HAVING count(*) >= ?
            SQL,
            [
                $context,
                $context,
                '{'.implode(',', array_map('intval', $groupIds)).'}',
                $min,
            ],
        );

        $strengths = [];

        foreach ($rows as $row) {
            $strength = CrowdVotes::strength(
                (int) $row->context_voters,
                (int) $row->context_up,
                (int) $row->all_voters,
                (int) $row->all_up,
                $min,
            );

            if ($strength !== 0.0) {
                $strengths[(int) $row->group_id] = $strength;
            }
        }

        return $strengths;
    }

    /** Every crowd vote this visitor cast: for a deleted account. */
    public static function forgetVoter(Owner $owner): void
    {
        $hash = $owner->identityHash(self::PURPOSE);

        if ($hash !== null) {
            GiftVote::query()->where('voter_hash', $hash)->delete();
        }
    }

    /**
     * How many different people must have voted on a product before the crowd
     * signal counts. Never below two, whatever the environment says: at one,
     * a single visitor's thumbs would steer everybody's results.
     */
    public static function minVoters(): int
    {
        return max(2, (int) config('giftcoves.gift.feedback.min_voters', 5));
    }

    /**
     * "Who is it for" in the closed vocabulary, or null. A saved person's
     * relationship is free text ("mama"), read the way the results page reads
     * it, so a vote for Mama and a vote for "a mother" meet.
     */
    private function context(?string $relationship, Market $market): ?RecipientType
    {
        return app(GiftResults::class)->relationshipType($relationship, $market);
    }
}
