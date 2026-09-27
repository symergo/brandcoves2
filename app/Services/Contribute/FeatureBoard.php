<?php

declare(strict_types=1);

namespace App\Services\Contribute;

use App\Enums\FeatureStatus;
use App\Enums\ModerationStatus;
use App\Models\FeatureIdea;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The voting board on the contribute page: which ideas, in which order, in
 * the reader's language (docs/features/contribute.md).
 *
 * ## The order
 *
 * What is being built, then what is planned, then what is being considered
 * with the most votes first, and what is done last (the page folds that group
 * away). Within a status, the admin's `sort` breaks ties before votes do for
 * everything but "considering": there the votes are the point, so they come
 * first, and `sort` only settles a draw.
 *
 * ## What a reader is sent
 *
 * The idea's text, its status, the count and whether this reader voted. Never
 * who voted and never who suggested it: a vote is a signal to us, not a
 * statement to the other visitors.
 */
final class FeatureBoard
{
    /**
     * The published ideas and their vote counts, shared by every reader.
     *
     * Five minutes: the board is read on every view of the contribute page
     * and changes when somebody votes or an idea is edited. A vote forgets it
     * (FeatureVoting), so a voter never sees their own vote missing from the
     * count; an edit in the admin shows within five minutes. Which ideas this
     * reader voted for is never cached: that is per person, asked per request.
     */
    public const CACHE_KEY = 'bc:feature-board';

    private const TTL = 300;

    /**
     * @return list<array{id: int, title: string, body: string, status: string, votes: int, votedByMe: bool, canVote: bool}>
     */
    public function ideas(?User $reader, string $language): array
    {
        // Raw rows in the cache and models rebuilt from them here: the cache
        // refuses to unserialise objects (`serializable_classes` is off).
        $ideas = FeatureIdea::hydrate(Cache::remember(
            self::CACHE_KEY,
            self::TTL,
            fn (): array => FeatureIdea::query()
                ->published()
                ->withCount('votes')
                ->get()
                ->map(fn (FeatureIdea $idea): array => $idea->getAttributes())
                ->all(),
        ));

        $mine = $reader === null
            ? []
            : array_flip($reader->featureVotes()->pluck('feature_idea_id')->all());

        $sorted = $ideas->sort(function (FeatureIdea $a, FeatureIdea $b): int {
            $byStatus = $a->status->rank() <=> $b->status->rank();

            if ($byStatus !== 0) {
                return $byStatus;
            }

            $byVotes = $b->votes_count <=> $a->votes_count;
            $bySort = $a->sort <=> $b->sort;

            $order = $a->status === FeatureStatus::Considering
                ? ($byVotes ?: $bySort)
                : ($bySort ?: $byVotes);

            return $order ?: $a->id <=> $b->id;
        });

        return $sorted->map(fn (FeatureIdea $idea): array => [
            'id' => $idea->id,
            'title' => $idea->text('title', $language),
            'body' => $idea->text('body', $language),
            'status' => $idea->status->value,
            'votes' => (int) $idea->votes_count,
            'votedByMe' => isset($mine[$idea->id]),
            // A done idea takes no more votes: there is nothing left to
            // decide about it, and a count still climbing on a finished
            // thing reads as a request nobody heard.
            'canVote' => $idea->status !== FeatureStatus::Done,
        ])->values()->all();
    }

    /**
     * The reader's own suggestions still waiting for a person, so the page
     * can say they arrived and are being looked at. Only pending ones: a
     * published suggestion is on the board with everybody else's.
     *
     * @return list<array{id: int, title: string}>
     */
    public function waitingFrom(?User $reader, string $language): array
    {
        if ($reader === null) {
            return [];
        }

        return FeatureIdea::query()
            ->where('suggested_by', $reader->id)
            ->where('moderation', ModerationStatus::Pending->value)
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (FeatureIdea $idea): array => [
                'id' => $idea->id,
                'title' => $idea->text('title', $language),
            ])
            ->values()
            ->all();
    }
}
