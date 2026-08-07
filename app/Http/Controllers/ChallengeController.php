<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPickSet;
use App\Models\Event;
use App\Services\Cove\PriceHunt;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A guess at the daily price hunt.
 *
 * JSON rather than an Inertia redirect: the round is a small stateful exchange
 * inside one page, and a full page response per guess would lose the input
 * focus and scroll position four times a round.
 *
 * Server-authoritative throughout. The client sends a number and is told a band;
 * it is never trusted to count attempts, decide whether the round is over, or
 * hold the answer.
 */
class ChallengeController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentMarket $current,
        PriceHunt $hunt,
        string $market,
        string $date,
    ): JsonResponse {
        $validated = $request->validate([
            // Euros in, cents stored. Bounded: a guess of a billion is not a
            // guess, and the band maths should never see a value that wide.
            'guess' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $edition = DailyPickSet::query()
            ->forMarket($current->get())
            ->published()
            ->where('drop_date', $date)
            ->whereNotNull('challenge_price')
            ->first();

        if ($edition === null) {
            throw new NotFoundHttpException;
        }

        $owner = Owner::fromRequest($request);

        // No account needed — the anonymous cookie identity is enough. Asking
        // someone to sign up before their first guess loses them, and the whole
        // point of the game is that it is the cheapest possible first step.
        abort_unless($owner->exists(), 403);

        $state = $hunt->guess($edition, $owner, (int) round((float) $validated['guess'] * 100));

        if ($state['finished']) {
            Event::record('cove.challenge_finished', [
                'market' => $current->value(),
                'solved' => $state['solved'],
                'attempts' => PriceHunt::MAX_ATTEMPTS - $state['attemptsLeft'],
            ]);
        }

        return response()->json([
            ...$state,
            'streak' => $hunt->streak($owner),
            'community' => $state['finished'] ? $hunt->communityResult($edition) : null,
            'productUrl' => $state['finished'] && $edition->challengeGroup !== null
                ? $current->url("p/{$edition->challenge_group_id}/{$edition->challengeGroup->slug}")
                : null,
        ]);
    }
}
