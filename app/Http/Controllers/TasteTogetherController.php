<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Recipient;
use App\Models\TasteInvite;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteChoiceReader;
use App\Services\Gift\TasteDeck;
use App\Services\Gift\TasteTogether;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * This or that together: several people play about one person, and the giver
 * sees what they found between them.
 *
 * Two sides:
 *
 * - **The giver**, on the person's list page: make a link, stop it, and add
 *   the combined result to the person. Behind `auth`, scoped to the owner,
 *   like every other write to a person (routes/web.php).
 * - **Whoever holds the link**, at `/t/{token}`: the ordinary This or that
 *   page, about that person, no account needed. The token is the whole
 *   permission, as with a list's share link, and grants playing and nothing
 *   else: the page gets the person's name and never anything the giver wrote
 *   about them, and a player never sees anyone else's answers.
 *
 * Extends TasteController for its rounds, its validation and its result, so
 * the rules of the game are written once. No AI anywhere (invariant 1).
 * See docs/features/taste-together.md.
 */
class TasteTogetherController extends TasteController
{
    /** Make the person's link, or hand back the open one. */
    public function store(Request $request, CurrentMarket $current, TasteTogether $together, string $market, string $recipient): RedirectResponse
    {
        $person = $this->owned($request, $recipient);

        $together->open($person, $current->get());

        return back()->with('success', __('site.gift.together.opened', ['name' => $person->name]));
    }

    /** Stop the link. What was chosen through it stays until it is pruned. */
    public function destroy(Request $request, TasteTogether $together, string $market, string $recipient): RedirectResponse
    {
        $person = $this->owned($request, $recipient);

        $together->stop($person);

        return back()->with('success', __('site.gift.together.stopped'));
    }

    /** Add what they found together to the person, the way "Save for" does. */
    public function apply(Request $request, TasteTogether $together, string $market, string $recipient): RedirectResponse
    {
        $person = $this->owned($request, $recipient);
        $invite = $together->latest($person);

        if ($invite === null) {
            throw new NotFoundHttpException;
        }

        $applied = $together->apply($invite);

        if ($applied === null) {
            return back()->with('success', __('site.gift.taste.nothing_to_save'));
        }

        return back()->with('success', __(
            $applied['written'] ? 'site.gift.taste.saved' : 'site.gift.taste.saved_theirs',
            ['name' => $person->name],
        ));
    }

    /** The game, for whoever holds the link. */
    public function play(Request $request, CurrentMarket $current, TasteDeck $deck, TasteTogether $together, string $market, string $token): Response
    {
        $invite = $this->invite($together, $token);

        $this->meta($invite);

        $room = $together->hasRoomFor($invite, $this->participant($request, $invite));

        return Inertia::render('Gift/Taste', [
            ...$this->page($current, $invite),
            'rounds' => $room ? $this->firstRounds($deck, $current) : [],
            'result' => null,
            'full' => ! $room,
        ]);
    }

    /**
     * One player's result, kept with the others.
     *
     * The player sees their own reading, worked out from their own choices,
     * and never the combined one: that is the giver's, and showing it here
     * would tell each player what the others chose.
     */
    public function finish(Request $request, CurrentMarket $current, TasteChoiceReader $reader, SuggestionEngine $engine, TasteTogether $together, string $market, string $token): Response
    {
        $invite = $this->invite($together, $token);
        $validated = $request->validate($this->choiceRules('required'));

        $this->meta($invite);

        $outcome = $this->outcome($validated['choices'], $current, $reader, $engine, 'someone', withIdeas: false);

        $recorded = $outcome['profile']['answered'] > 0 && $together->record(
            $invite,
            $this->participant($request, $invite),
            $validated['choices'],
            (int) $outcome['profile']['answered'],
        );

        return Inertia::render('Gift/Taste', [
            ...$this->page($current, $invite),
            'rounds' => [],
            'result' => [...$outcome, 'recorded' => $recorded],
            'full' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function page(CurrentMarket $current, TasteInvite $invite): array
    {
        return [
            'mode' => 'together',
            // Their name as the giver saved it, and nothing else the giver wrote.
            'person' => ['name' => $invite->recipient->name],
            'urls' => [
                'next' => $current->url('gift/taste/next'),
                'result' => $current->url("t/{$invite->token}"),
                'save' => '',
                'restart' => $current->url("t/{$invite->token}"),
                'finder' => $current->url('gift'),
                'card' => '',
            ],
            'total' => TasteDeck::ROUNDS,
            'recipients' => [],
            'canCreate' => false,
        ];
    }

    private function meta(TasteInvite $invite): void
    {
        // Never indexed: the token is the access, as on /for/{token}.
        app(PageMeta::class)->set(
            title: __('site.gift.together.title', ['name' => $invite->recipient->name]),
            robots: 'noindex, nofollow',
        );
    }

    /**
     * Who is playing, as a hash nobody can read back.
     *
     * The visitor's cookie identity, hashed with the invite as its purpose, so
     * the same visitor gets a different value on every link and no value can
     * be matched against a claim, a vote or another link's players
     * (Owner::identityHash). The identity middleware gives every visitor one;
     * the session id is the fallback for a request that somehow has none.
     */
    private function participant(Request $request, TasteInvite $invite): string
    {
        return Owner::fromRequest($request)->identityHash("taste-together|{$invite->id}")
            ?? hash_hmac('sha256', "taste-together|{$invite->id}|".$request->session()->getId(), (string) config('app.key'));
    }

    private function invite(TasteTogether $together, string $token): TasteInvite
    {
        $invite = $together->byToken($token);

        if ($invite === null || $invite->recipient === null) {
            throw new NotFoundHttpException;
        }

        return $invite;
    }

    private function owned(Request $request, string $id): Recipient
    {
        $recipient = Owner::fromRequest($request)->scope(Recipient::query())->find($id);

        if ($recipient === null) {
            throw new NotFoundHttpException;
        }

        return $recipient;
    }
}
