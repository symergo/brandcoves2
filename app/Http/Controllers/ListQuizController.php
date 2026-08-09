<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListVisibility;
use App\Models\ListQuiz;
use App\Models\ListQuizAttempt;
use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Services\Gift\QuizBuilder;
use App\Support\CurrentMarket;
use App\Support\ListAccess;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "How well do you know them?"
 *
 * ## Rules that are not negotiable
 *
 * - **The owner cannot play their own quiz.** It is meaningless, and more
 *   importantly it must never become a channel that tells them anything about
 *   what has been claimed.
 * - **It runs only on a list the player could already open.** Never a private
 *   one, so playing cannot become a way to enumerate a hidden list.
 * - **No claim state anywhere.** Invariant #4 covers the quiz exactly as it
 *   covers the list it is built from.
 * - **Playable signed-out**, on the anonymous cookie identity, hashed with a
 *   dedicated purpose — the reason `Owner::identityHash()` takes an argument at
 *   all.
 */
class ListQuizController extends Controller
{
    /** Create a quiz for a list you own. */
    public function store(Request $request, CurrentMarket $current, string $market, string $list, QuizBuilder $builder): RedirectResponse
    {
        $owner = Owner::fromRequest($request);

        $wishlist = $owner->scope(Wishlist::query())->with('items.group')->find($list);

        if ($wishlist === null || ! ListAccess::isOwner($wishlist, $owner)) {
            throw new NotFoundHttpException;
        }

        /*
         * A quiz reveals what is on the list, so the list must already be
         * shareable. Publishing one over a private list would be a way to leak
         * it that never went through the sharing switch.
         */
        abort_if($wishlist->visibility === ListVisibility::Private, 403, __('site.quiz.share_first'));

        $rounds = $builder->build($wishlist, $this->pool($wishlist, $current));

        if ($rounds === []) {
            // Say so, rather than shipping a two-question quiz.
            return back()->with('error', __('site.quiz.too_short', ['count' => QuizBuilder::MIN_ITEMS]));
        }

        $quiz = ListQuiz::updateOrCreate(
            ['wishlist_id' => $wishlist->id],
            ['market' => $current->get(), 'rounds' => $rounds],
        );

        return back()->with('success', __('site.quiz.created'))->with('quizUrl', url($current->url("q/{$quiz->share_token}")));
    }

    public function show(Request $request, CurrentMarket $current, string $market, string $token): Response
    {
        $quiz = $this->findByToken($token);
        $owner = Owner::fromRequest($request);

        $isOwner = ListAccess::isOwner($quiz->wishlist, $owner);
        $hash = $owner->identityHash('quiz');

        $attempt = $hash === null ? null : $this->attemptFor($quiz, $owner);

        return Inertia::render('Quiz/Play', [
            'quiz' => [
                'title' => $quiz->wishlist->title,
                'owner' => $quiz->wishlist->owner?->displayName(),
                // Questions only. The payload a player receives must not carry
                // the thing they are being asked to guess.
                'rounds' => $quiz->questions(),

                /*
                 * The absolute link to this quiz, minted here rather than read
                 * off `window.location` in the browser. Sharing a score is the
                 * whole point of the page, and a component that reaches for
                 * `window` cannot be server-rendered at all.
                 */
                'shareUrl' => url($current->url("q/{$quiz->share_token}")),
            ],

            // Meaningless for them, and it must never tell them anything.
            'isOwner' => $isOwner,

            'result' => $attempt === null ? null : [
                'score' => $attempt->score,
                'total' => count($quiz->answers()),
                'grid' => $this->grid($attempt, $quiz),
            ],

            'stats' => $this->stats($quiz),
        ]);
    }

    public function submit(Request $request, CurrentMarket $current, string $market, string $token): RedirectResponse
    {
        $quiz = $this->findByToken($token);
        $owner = Owner::fromRequest($request);

        abort_if(ListAccess::isOwner($quiz->wishlist, $owner), 403);
        abort_unless($owner->exists(), 403);

        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'integer'],
        ]);

        $answers = array_map('intval', $validated['answers']);
        $correct = $quiz->answers();

        $score = 0;

        foreach ($correct as $index => $expected) {
            if (($answers[$index] ?? null) === $expected) {
                $score++;
            }
        }

        /*
         * One attempt per player.
         *
         * Replaying until you score five out of five is not a score anybody
         * would want to post, and a leaderboard of perfect scores says nothing
         * about how well anyone knows anyone.
         */
        ListQuizAttempt::updateOrCreate(
            [
                'quiz_id' => $quiz->id,
                ...$owner->attributes('user_id', 'anon_id'),
            ],
            [
                'answers' => $answers,
                'score' => $score,
                'played_on' => now()->toDateString(),
            ],
        );

        return back();
    }

    /**
     * The share grid.
     *
     * Squares, not a link and not the answers. It works because the artefact is
     * a *score*: nobody feels marketed to by a row of emoji, it carries no
     * spoiler so posting it costs the poster nothing, and everyone playing the
     * same quiz makes a posted result a conversation.
     *
     * Readable without colour, per `GuessBand::emoji()` — a row of squares has to
     * survive being pasted into a plain-text message.
     */
    private function grid(ListQuizAttempt $attempt, ListQuiz $quiz): string
    {
        $answers = (array) $attempt->answers;

        return implode('', array_map(
            fn (int $expected, int $index) => ($answers[$index] ?? null) === $expected ? '🟩' : '⬜',
            $quiz->answers(),
            array_keys($quiz->answers()),
        ));
    }

    /**
     * What the owner is allowed to know: an aggregate, carrying nothing about
     * claims and nothing about who played.
     *
     * @return array{played: int, average: float|null}
     */
    private function stats(ListQuiz $quiz): array
    {
        $played = $quiz->attempts()->count();

        return [
            'played' => $played,
            'average' => $played === 0 ? null : round((float) $quiz->attempts()->avg('score'), 1),
        ];
    }

    private function attemptFor(ListQuiz $quiz, Owner $owner): ?ListQuizAttempt
    {
        return $owner
            ->scope($quiz->attempts()->getQuery(), 'user_id', 'anon_id')
            ->first();
    }

    /**
     * Candidate wrong answers.
     *
     * Giftable and in-market, because a printer cartridge among the options
     * gives the answer away as surely as a chainsaw does.
     *
     * @return Collection<int, ProductGroup>
     */
    private function pool(Wishlist $wishlist, CurrentMarket $current)
    {
        return ProductGroup::query()
            ->forMarket($current->get())
            ->giftable()
            ->presentable()
            ->inRandomOrder()
            ->limit(400)
            ->get();
    }

    private function findByToken(string $token): ListQuiz
    {
        $quiz = ListQuiz::query()
            ->with(['wishlist.owner'])
            ->where('share_token', $token)
            ->first();

        // A quiz over a list that has since been un-shared goes with it: the
        // sharing switch has to actually turn everything off.
        if ($quiz === null || $quiz->wishlist === null || $quiz->wishlist->visibility === ListVisibility::Private) {
            throw new NotFoundHttpException;
        }

        return $quiz;
    }
}
