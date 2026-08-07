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

            $anon->update([
                'merged_into_user_id' => $user->id,
                'merged_at' => now(),
            ]);

            if ($recipients > 0 || $wishlists > 0) {
                Log::info('Anonymous identity merged', [
                    'user_id' => $user->id,
                    'recipients' => $recipients,
                    'wishlists' => $wishlists,
                ]);
            }
        });
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
     */
    public function claimsAreIntentionallyNotMerged(): bool
    {
        return true;
    }
}
