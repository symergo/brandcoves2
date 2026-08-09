<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListKind;
use App\Enums\RecipientStatus;
use App\Enums\TasteSource;
use App\Http\Requests\RecipientTasteRequest;
use App\Models\Recipient;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\SuggestionProfile;
use App\Services\Gift\TasteBrief;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Tell them what you'd actually like."
 *
 * `recipients.share_token` has been minted on every row since the table was
 * created, with a comment saying it lets the recipient fill in their own tastes.
 * No route ever resolved it, so gifting here has only ever had one participant —
 * and "what they actually asked for" is the half that matters most.
 *
 * ## The link is a capability
 *
 * Holding the token is the whole of the authorisation, exactly as with
 * `/l/{token}`. It grants describing yourself and curating **your own** list,
 * and nothing else. It is not a login and must never become one.
 *
 * ## The rule this page exists under
 *
 * > It shows the recipient's own list and never anything the giver did.
 *
 * Not the giver's research list, not a count of it, not claim state on their own
 * items, not the giver's private notes (`Recipient::$hidden`). The person on the
 * other end of this link is precisely the person the surprise is being kept
 * from.
 */
class RecipientProfileController extends Controller
{
    public function show(Request $request, CurrentMarket $current, string $market, string $token): Response
    {
        $recipient = $this->findByToken($token);
        $owner = Owner::fromRequest($request);

        $list = $this->theirList($recipient, $owner, $current);

        return Inertia::render('Recipients/SelfDescribe', [
            'person' => [
                'name' => $recipient->name,
                // Their own answers, or blank. Deliberately NOT prefilled with
                // the giver's guesses: seeing "we heard you like gardening"
                // reveals what they have been told about, and anchors the
                // answer to somebody else's idea of them.
                'interests' => $recipient->taste_source === TasteSource::Self
                    ? (array) $recipient->interests
                    : [],
                'vibe' => $recipient->taste_source === TasteSource::Self ? $recipient->vibe : null,
                'values' => $recipient->taste_source === TasteSource::Self
                    ? (array) $recipient->values
                    : [],
                'hasSpoken' => $recipient->taste_source === TasteSource::Self,
                'isLinked' => $recipient->isLinked(),
            ],
            'options' => RecipientTasteRequest::options(),
            'canClaim' => $owner->isSignedIn() && ! $recipient->isLinked(),
            'items' => $list === null ? [] : $this->items($list),
            'listId' => $list?->id,

            /*
             * No `giverList`, no `pickedCount`, no claim state. Their absence is
             * the feature — see the class docblock.
             */
        ]);
    }

    /** Their own words about themselves, which outrank anyone's guess. */
    public function update(RecipientTasteRequest $request, CurrentMarket $current, string $market, string $token): RedirectResponse
    {
        $recipient = $this->findByToken($token);

        // Only taste. `context()` holds the giver's relationship, occasion,
        // budget and private notes — none of which belong to the person being
        // described, and `notes` in particular is written *about* them.
        $recipient->describeTaste($request->taste(), TasteSource::Self);

        return back()->with('success', __('site.recipients.saved'));
    }

    /**
     * Bind this person to the signed-in account.
     *
     * What the link was always for: once bound, their own shared lists can
     * appear on the giver's page as "what they asked for", and the giver stops
     * guessing.
     */
    public function claim(Request $request, CurrentMarket $current, string $market, string $token): RedirectResponse
    {
        $recipient = $this->findByToken($token);
        $user = $request->user();

        abort_if($user === null, 403);

        if ($recipient->isLinked()) {
            return back();
        }

        // The owner claiming their own stub would make them the recipient of
        // their own gift research, which is not a thing.
        abort_if($recipient->owner_user_id === $user->id, 403);

        $recipient->update([
            'user_id' => $user->id,
            'status' => RecipientStatus::Linked,
        ]);

        return back()->with('success', __('site.recipients.linked'));
    }

    /**
     * Suggestions to seed a list from, ranked for the person themselves.
     *
     * Staring at an empty wishlist is the reason most of them stay empty; a
     * typed query assumes you already know what you want, which is exactly what
     * you do not. Ranked with the self profile so cheap things are not quietly
     * buried — see {@see SuggestionProfile::budgetFit()}.
     */
    public function suggest(Request $request, CurrentMarket $current, string $market, string $token, SuggestionEngine $engine): Response
    {
        $recipient = $this->findByToken($token);

        $brief = TasteBrief::fromRecipient($recipient, $current->get(), 8)
            ->rankedAs(SuggestionProfile::forMyself())
            ->searching($request->string('q')->toString() ?: null);

        return Inertia::render('Recipients/SelfDescribe', [
            'suggestions' => array_map(fn ($pick) => [
                'id' => $pick->group->id,
                'title' => $pick->group->title,
                'image' => $pick->group->image_url,
                'price' => $pick->group->min_price,
                'reason' => $pick->topSignal(),
            ], $engine->suggest($brief)),
        ]);
    }

    /**
     * The list this person is building, created on demand.
     *
     * Owned by whoever opens the link — a signed-in user or the anonymous cookie
     * identity — with `recipient_id` pointing back. That keeps the single-owner
     * CHECK constraint intact and lets `IdentityMerger` fold the list into a
     * real account later, the way every other anonymous list already works.
     */
    private function theirList(Recipient $recipient, Owner $owner, CurrentMarket $current): ?Wishlist
    {
        if (! $owner->exists()) {
            return null;
        }

        return Wishlist::firstOrCreate(
            [
                'recipient_id' => $recipient->id,
                'kind' => ListKind::Mine->value,
                ...$owner->attributes(),
            ],
            [
                'title' => __('site.recipients.my_list', ['name' => $recipient->name]),
                'market' => $current->get(),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(Wishlist $list): array
    {
        return $list->items()->with('group')->get()->map(fn (WishlistItem $item) => [
            'id' => $item->id,
            'title' => $item->snapshot_title,
            'image' => $item->snapshot_image_url,
            'price' => $item->snapshot_price,
            'note' => $item->note,
            'live' => $item->rendersLive(),
            // No claim key of any shape. This is the owner's own view of their
            // own list, and it is also the view of the one person who must
            // never learn what has been bought.
        ])->all();
    }

    private function findByToken(string $token): Recipient
    {
        $recipient = Recipient::query()->where('share_token', $token)->first();

        if ($recipient === null) {
            throw new NotFoundHttpException;
        }

        return $recipient;
    }
}
