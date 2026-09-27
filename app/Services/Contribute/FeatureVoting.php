<?php

declare(strict_types=1);

namespace App\Services\Contribute;

use App\Enums\FeatureStatus;
use App\Models\FeatureIdea;
use App\Models\FeatureVote;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

/**
 * One vote per signed-in person per idea, and taking it back.
 *
 * Signed in, because a vote nobody can be held to one of is not a vote: an
 * anonymous count is one refresh away from any number somebody likes, and
 * the board exists to tell us what people want, not who can press fastest.
 *
 * Idempotent both ways. Voting twice leaves one vote (the unique index would
 * refuse a second row anyway), and withdrawing a vote that is not there does
 * nothing, so a double tap or a stale page never errors.
 */
final class FeatureVoting
{
    /**
     * Only an idea the public can see and that is not done yet. A pending or
     * rejected suggestion must not be reachable by guessing its id.
     */
    public function votable(int $id): FeatureIdea
    {
        $idea = FeatureIdea::query()->published()->find($id);

        if ($idea === null || $idea->status === FeatureStatus::Done) {
            throw (new ModelNotFoundException)->setModel(FeatureIdea::class, [$id]);
        }

        return $idea;
    }

    public function vote(User $user, FeatureIdea $idea): void
    {
        FeatureVote::query()->firstOrCreate([
            'feature_idea_id' => $idea->id,
            'user_id' => $user->id,
        ]);

        // The board's shared counts, so the voter sees their vote counted.
        Cache::forget(FeatureBoard::CACHE_KEY);
    }

    public function withdraw(User $user, FeatureIdea $idea): void
    {
        FeatureVote::query()
            ->where('feature_idea_id', $idea->id)
            ->where('user_id', $user->id)
            ->delete();

        Cache::forget(FeatureBoard::CACHE_KEY);
    }
}
