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
        return Inertia::render(
            'Recipients/SelfDescribe',
            $this->page($request, $current, $this->findByToken($token)),
        );
    }

    /**
     * Everything this page needs, built once for both routes.
     *
     * `suggest()` used to render `Recipients/SelfDescribe` with **only**
     * `suggestions`, and the page reaches it through `router.get()` — a full
     * visit, not a partial reload. So searching replaced the whole prop set
     * with one key: `person` came back undefined and the page died on
     * `person.name`, which is "the search does not work" as a person
     * experiences it.
     *
     * Fixed by making the payload whole rather than by asking the client for a
     * partial reload. `only: ['suggestions']` would also have worked and is
     * one property short of correct: a URL that renders a broken page when
     * somebody refreshes it, or opens it from their history, is broken.
     * `/for/{token}/suggest?q=…` is a real address and has to stand on its own.
     *
     * @return array<string, mixed>
     */
    private function page(Request $request, CurrentMarket $current, Recipient $recipient): array
    {
        $owner = Owner::fromRequest($request);

        $list = $this->theirList($recipient, $owner, $current);

        return [
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

                /*
                 * Their birthday, as the day and month they gave.
                 *
                 * Sent back so the field is not blank every time they return —
                 * unlike the taste answers above, which are deliberately not
                 * prefilled from the *giver's* guesses. There is no equivalent
                 * hazard here: a date is a fact rather than a characterisation,
                 * and it is theirs whoever typed it.
                 *
                 * The year is never sent, because the year is never asked for.
                 * See `Recipient::BIRTHDAY_YEAR`.
                 */
                'birthdayDay' => $recipient->birthday?->day,
                'birthdayMonth' => $recipient->birthday?->month,
            ],
            'options' => RecipientTasteRequest::options(),
            /*
             * Mirrors `claim()` exactly, and used not to.
             *
             * It was `isSignedIn() && ! isLinked()`, which omits the one refusal
             * the endpoint actually makes: the **giver** cannot claim their own
             * stub, because that would make them the recipient of their own
             * gift research. So the person most likely to open this link — the
             * one who made the list, checking what it looks like — was shown a
             * button that answered 403 with no explanation.
             *
             * A control that 403s when pressed is worse than no control. Same
             * defect, same fix, as `allowsContributionsFrom()` and `canSuggest`.
             */
            'canClaim' => $owner->isSignedIn()
                && ! $recipient->isLinked()
                && $recipient->owner_user_id !== $request->user()?->id,

            /*
             * Is this the person who made the list, looking at their own link?
             *
             * Worth saying out loud rather than rendering nothing: they came
             * here to see what they were about to send, and a page that simply
             * omits the control leaves them wondering whether it is broken.
             */
            'isGiver' => $recipient->owner_user_id !== null
                && $recipient->owner_user_id === $request->user()?->id,

            // Signed out, on a link somebody sent them. Describing yourself
            // needs no account; saying "this is me" is what needs one, and it
            // is the short path to having one.
            'canSignInToClaim' => ! $owner->isSignedIn() && ! $recipient->isLinked(),
            'items' => $list === null ? [] : $this->items($list),
            'listId' => $list?->id,

            /*
             * No `giverList`, no `pickedCount`, no claim state. Their absence is
             * the feature — see the class docblock.
             */
        ];
    }

    /** Their own words about themselves, which outrank anyone's guess. */
    public function update(RecipientTasteRequest $request, CurrentMarket $current, string $market, string $token): RedirectResponse
    {
        $recipient = $this->findByToken($token);

        // Only taste. `context()` holds the giver's relationship, occasion,
        // budget and private notes — none of which belong to the person being
        // described, and `notes` in particular is written *about* them.
        $recipient->describeTaste($request->taste(), TasteSource::Self);

        /*
         * Their birthday, if they gave one.
         *
         * Not part of `taste()` and deliberately separate from it: taste is a
         * characterisation and this is a fact, and the two are stored,
         * overwritten and reasoned about differently — `describeTaste()` stamps
         * a `TasteSource`, and a date has no source worth recording.
         *
         * Absent means "left blank", which leaves what is stored alone. Only a
         * real day-and-month pair writes: somebody who fills in their interests
         * and skips the date must not thereby erase a date the giver already
         * knew.
         */
        $birthday = Recipient::birthdayFrom(
            $request->integer('birthday_day') ?: null,
            $request->integer('birthday_month') ?: null,
        );

        if ($birthday !== null) {
            $recipient->update(['birthday' => $birthday]);
        }

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
            // The whole page, plus the results. This route is reached by a full
            // visit and is a real address somebody can refresh; see `page()`.
            ...$this->page($request, $current, $recipient),

            'suggestions' => array_map(fn ($pick) => [
                'id' => $pick->group->id,
                'title' => $pick->group->title,
                'image' => $pick->group->image_url,
                'price' => $pick->group->min_price,
                'reason' => $pick->topSignal(),
            ], $engine->suggest($brief)),

            // So the box still holds what they typed after the page comes back.
            'suggestTerm' => $request->string('q')->toString(),
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
