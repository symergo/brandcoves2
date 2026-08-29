<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ListItemVote;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "This is the one we should get."
 *
 * Phase 3 of the list taxonomy, and the half a group list was missing: the
 * money pooled fine, and choosing what to spend it on happened in the group
 * chat. A shortlist with no way to choose from it is a list.
 *
 * Approval voting — any member backs any number of candidates, one row per
 * person per item. Not "pick one favourite", which forces a decision the group
 * has not made; the shortlist exists precisely because nobody has.
 *
 * ## The tally is public and the voter is not
 *
 * "Four people want the espresso machine" is the fact that decides something.
 * "Bob wanted the espresso machine" is a disagreement waiting to happen inside
 * a group buying somebody a present, and it is not needed for anything — so no
 * payload ever names a voter, and `ListItemVote::$hidden` covers the route
 * nobody intended.
 */
class ListItemVoteController extends Controller
{
    public function store(Request $request, CurrentMarket $current, string $market, string $token, string $item): RedirectResponse
    {
        [$wishlistItem, $owner] = $this->resolve($request, $token, $item);

        /*
         * `ON CONFLICT DO NOTHING`, rather than an insert that might throw.
         *
         * The unique index is what decides — `updateOrCreate` is a
         * read-then-write, and two taps on a phone that has not finished the
         * first request is the ordinary case rather than an exotic one. But
         * *catching* the violation is not good enough either: in Postgres a
         * failed statement aborts the surrounding transaction, so a swallowed
         * exception leaves every later query in that request answering
         * "current transaction is aborted". Caught by a test that presses the
         * button twice, which is exactly what a person does.
         *
         * `insertOrIgnore` never raises, so the second press is a no-op and the
         * state is the one they asked for: their vote is on the item.
         * Timestamps by hand, because it bypasses the model.
         */
        ListItemVote::query()->insertOrIgnore([
            'item_id' => $wishlistItem->id,
            ...$owner->attributes('user_id', 'anon_id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back();
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $token, string $item): RedirectResponse
    {
        [$wishlistItem, $owner] = $this->resolve($request, $token, $item);

        // Scoped to this voter: taking back somebody else's vote is not a thing
        // the button does, and a hand-built request must not do it either.
        $owner->scope(
            ListItemVote::query()->where('item_id', $wishlistItem->id),
            'user_id',
            'anon_id',
        )->delete();

        return back();
    }

    /**
     * @return array{0: WishlistItem, 1: Owner}
     */
    private function resolve(Request $request, string $token, string $item): array
    {
        $list = Wishlist::query()
            ->where('share_token', $token)
            ->where('visibility', '!=', 'private')
            ->first();

        if ($list === null) {
            throw new NotFoundHttpException;
        }

        $owner = Owner::fromRequest($request);

        // One question, asked on the model, so the button and the POST cannot
        // disagree about it.
        abort_unless($list->allowsVotingFrom($owner), 403);

        $wishlistItem = $list->items()->whereKey($item)->first();

        if ($wishlistItem === null) {
            throw new NotFoundHttpException;
        }

        return [$wishlistItem, $owner];
    }
}
