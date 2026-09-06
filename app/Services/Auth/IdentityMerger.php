<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\AnonymousIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves everything built anonymously onto a real account at sign-in.
 *
 * This is what makes "useful before you sign up" true rather than a slogan. A
 * visitor builds a gift list over several visits, finally creates an account,
 * and the list has to still be there — losing it is the single worst moment the
 * product can produce, because it destroys work the person did themselves.
 */
class IdentityMerger
{
    public function merge(AnonymousIdentity $anon, User $user): void
    {
        // Already merged: a second sign-in from the same browser must not move
        // rows a third time or re-parent someone else's data.
        if ($anon->isMerged()) {
            return;
        }

        DB::transaction(function () use ($anon, $user): void {
            // Recipients first — wishlists reference them, and re-parenting a
            // list whose recipient still belonged to the anonymous identity
            // would violate the one-owner check constraint.
            $recipients = DB::table('recipients')
                ->where('owner_anon_id', $anon->getKey())
                ->update([
                    'owner_user_id' => $user->id,
                    'owner_anon_id' => null,
                    'updated_at' => now(),
                ]);

            $wishlists = DB::table('wishlists')
                ->where('owner_anon_id', $anon->getKey())
                ->update([
                    'owner_user_id' => $user->id,
                    'owner_anon_id' => null,
                    'updated_at' => now(),
                ]);

            // Interaction history follows the person, so the learning loop does
            // not treat pre- and post-signup behaviour as two strangers.
            DB::table('events')
                ->where('anon_id', $anon->getKey())
                ->update(['user_id' => $user->id]);

            /*
             * Pledges, votes and game attempts follow the person too.
             *
             * Until 2026-09-06 only the three tables above moved, so a pledge
             * made before signing up was money the person could no longer see
             * or withdraw: once signed in, the cookie identity is never
             * resolved again. The same for a vote on a group list and a quiz
             * score.
             *
             * All four carry a one-row-per-person rule (a partial unique
             * index each: per item, per list, per quiz, per edition). Where
             * the account already holds its own row for the same thing, the
             * anonymous vote or attempt is dropped — it is a duplicate of one
             * the person has — and the anonymous pledge is left where it is,
             * still counted in the pot: money is not something to delete or
             * double on the quiet.
             */
            $this->dropDuplicates('list_item_votes', 'item_id', $anon, $user);
            $this->dropDuplicates('list_quiz_attempts', 'quiz_id', $anon, $user);
            $this->dropDuplicates('challenge_attempts', 'set_id', $anon, $user);

            $votes = $this->reparent('list_item_votes', $anon, $user);
            $this->reparent('list_quiz_attempts', $anon, $user);
            $this->reparent('challenge_attempts', $anon, $user);

            $pledges = DB::table('gift_pledges as anon')
                ->where('anon.anon_id', $anon->getKey())
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('gift_pledges as mine')
                    ->where('mine.user_id', $user->id)
                    ->whereColumn('mine.wishlist_id', 'anon.wishlist_id'))
                ->update(['user_id' => $user->id, 'anon_id' => null, 'updated_at' => now()]);

            $anon->update([
                'merged_into_user_id' => $user->id,
                'merged_at' => now(),
            ]);

            if ($recipients > 0 || $wishlists > 0 || $votes > 0 || $pledges > 0) {
                Log::info('Anonymous identity merged', [
                    'user_id' => $user->id,
                    'recipients' => $recipients,
                    'wishlists' => $wishlists,
                    'votes' => $votes,
                    'pledges' => $pledges,
                ]);
            }
        });
    }

    /**
     * Delete the anonymous rows that would collide with one the account holds.
     *
     * @param  string  $key  the column that, with the person, is unique
     */
    private function dropDuplicates(string $table, string $key, AnonymousIdentity $anon, User $user): void
    {
        DB::table("{$table} as anon")
            ->where('anon.anon_id', $anon->getKey())
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from("{$table} as mine")
                ->where('mine.user_id', $user->id)
                ->whereColumn("mine.{$key}", "anon.{$key}"))
            ->delete();
    }

    /** @return int rows moved */
    private function reparent(string $table, AnonymousIdentity $anon, User $user): int
    {
        return DB::table($table)
            ->where('anon_id', $anon->getKey())
            ->update(['user_id' => $user->id, 'anon_id' => null, 'updated_at' => now()]);
    }

    /**
     * Claims made anonymously are deliberately NOT re-parented.
     *
     * A claim is stored as a one-way hash of the claimer's identity, and the
     * anonymous and signed-in hashes differ. Rewriting them would mean
     * recomputing a hash for a person we can now name — and the whole point of
     * the hash is that the list owner can never learn who claimed what. Leaving
     * them alone costs the claimer the ability to un-claim from a fresh account,
     * which is a far smaller harm than leaking the surprise.
     *
     * **That cost is why claiming now needs an account** (2026-09-06). This
     * method was the honest answer to a question that should not have been
     * asked: a claim that cannot follow its claimer to a second device is a
     * claim they cannot revisit or release. New claims are hashed from a user
     * id, which is stable forever, so there is nothing left to merge. The rows
     * this refuses to touch are the anonymous ones already in the database, and
     * `SharedListController::unclaim()` stays open to a cookie identity so
     * their owners can still hand them back. See
     * docs/features/wishlists.md and App\Services\Wishlist\PendingClaim.
     */
    public function claimsAreIntentionallyNotMerged(): bool
    {
        return true;
    }
}
