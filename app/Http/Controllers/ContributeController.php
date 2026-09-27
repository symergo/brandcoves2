<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Contribute\FeatureBoard;
use App\Services\Contribute\FeatureSuggestions;
use App\Services\Contribute\FeatureVoting;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use App\Support\RefererPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Denk mee": feedback, the ideas we are weighing, and suggesting one
 * (owner, 2026-09-27; docs/features/contribute.md).
 *
 * Open to everybody: a guest reads the board and the counts, sends feedback
 * (the same form as /help, no account) and is offered a sign-in to vote or
 * suggest. Voting and suggesting need an account, because a vote nobody can
 * be held to one of is a number anybody can move.
 *
 * Thin on purpose: the order of the board, the rules for a vote and the
 * limits on suggestions are in App\Services\Contribute.
 */
class ContributeController extends Controller
{
    public function index(Request $request, CurrentMarket $current, FeatureBoard $board): Response
    {
        $market = $current->get();

        app(PageMeta::class)->set(
            title: __('site.contribute.seo_title'),
            description: __('site.contribute.seo_description'),
            canonical: url($current->url('contribute')),
            // Indexable: it is a public page, and "what is GiftCoves building"
            // is a fair thing to find.
            robots: null,
        );

        $user = $request->user();

        return Inertia::render('Contribute', [
            'ideas' => $board->ideas($user, $market->language()),
            'waiting' => $board->waitingFrom($user, $market->language()),
            'isSignedIn' => $user !== null,
            // The page they came from, for the feedback form, as on /help.
            'path' => RefererPath::of($request),
        ]);
    }

    public function vote(Request $request, FeatureVoting $voting, string $market, string $idea): RedirectResponse
    {
        $voting->vote($request->user(), $voting->votable((int) $idea));

        return back(303);
    }

    public function unvote(Request $request, FeatureVoting $voting, string $market, string $idea): RedirectResponse
    {
        $voting->withdraw($request->user(), $voting->votable((int) $idea));

        return back(303);
    }

    public function suggest(Request $request, CurrentMarket $current, FeatureSuggestions $suggestions): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * Honest, unlike the anonymous feedback form's silent thank-you: this
         * is a signed-in person, not a script probing where the line is, and
         * "try again tomorrow" is more use to them than a thanks for a
         * suggestion that was not kept.
         */
        if (! $suggestions->canSuggest($request->user())) {
            throw ValidationException::withMessages([
                'title' => __('site.contribute.suggest_limit', ['count' => FeatureSuggestions::PER_DAY]),
            ]);
        }

        $suggestions->suggest($request->user(), $current->get(), $validated['title'], $validated['body'] ?? null);

        return back(303)->with('status', __('site.contribute.suggest_thanks'));
    }
}
