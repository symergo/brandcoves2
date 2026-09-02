<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ListMessage;
use App\Models\Wishlist;
use App\Services\Wishlist\Board;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The discussion beside a shared list.
 *
 * The conversation that decides the buying used to happen in the group chat the
 * link was pasted into — a window with none of the facts in it. Four people
 * arguing about whether to go halves on the coat could not see what had been
 * claimed, what the pot stood at, or what was still unspoken-for, because all
 * of that was on a page nobody was looking at while they talked.
 *
 * ## Who may write here is who may read here
 *
 * Both answered by {@see Board}, which hangs the whole question off
 * `Wishlist::shouldHideClaimsFrom()` — invariant #4's own gate. A board is free
 * text written by the people doing the buying, and "I've got the scarf, someone
 * take the boots" is claim state in prose; a second rule beside the claim rule
 * would be a second rule to keep in step with it.
 *
 * Asked here as well as at the page, and that is not belt and braces: hiding a
 * form stops nobody hand-building the POST.
 *
 * ## It answers JSON, not a redirect
 *
 * A conversation that reloads the page after every line is not a conversation.
 * Both endpoints answer with JSON when the caller asks for it — the created
 * row, or `{ok: true}` — and the board appends or removes it in place; the rest
 * of the page, which is a list of products and a pot, does not change because
 * somebody typed a sentence.
 *
 * The redirect stays for a caller that did not ask for JSON. It costs one line
 * and it is what makes the form work with the script gone.
 *
 * ## Deletion is the moderation control
 *
 * Not screening. `Community\PostScreen` holds anything carrying a link or a
 * phone number, which is right on a public board answered by strangers and
 * wrong here — this is a handful of people who were sent a link by a friend,
 * and "call me on 06…" is the ordinary case rather than the abuse. The author
 * may take their own message back, and the list's owner may remove any of them.
 */
class ListMessageController extends Controller
{
    public function __construct(private readonly Board $board) {}

    public function store(
        Request $request,
        CurrentMarket $current,
        string $market,
        string $token,
    ): RedirectResponse|JsonResponse {
        [$list, $viewer] = $this->resolve($request, $token);

        $validated = $request->validate([
            /*
             * Long enough for "shall we go halves on the coat? I can do €40",
             * short enough that the rail stays a rail. A board that accepts an
             * essay becomes a page inside a page.
             */
            'body' => ['required', 'string', 'max:1000'],
            // Typed per message, like a pledge: half the people here have no
            // account to take a name from.
            'display_name' => ['required', 'string', 'max:80'],
        ]);

        $message = ListMessage::create([
            'wishlist_id' => $list->id,
            ...$viewer->attributes('user_id', 'anon_id'),
            'display_name' => $validated['display_name'],
            'body' => $validated['body'],
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->board->present($message, $list, $viewer),
            ], 201);
        }

        return back()->with('success', __('site.board.posted'));
    }

    public function destroy(
        Request $request,
        CurrentMarket $current,
        string $market,
        string $token,
        ListMessage $message,
    ): RedirectResponse|JsonResponse {
        [$list, $viewer] = $this->resolve($request, $token);

        // A message from another list reached by this list's token is a 404,
        // not a 403: the row is none of this URL's business either way.
        if ($message->wishlist_id !== $list->id) {
            throw new NotFoundHttpException;
        }

        // Yours, or the list owner's to remove. Asked again here because the
        // page's `removable` flag decides a button and nothing more.
        abort_unless($message->wasWrittenBy($viewer) || $list->isOwnedBy($viewer), 403);

        $message->delete();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', __('site.board.removed'));
    }

    /**
     * @return array{0: Wishlist, 1: Owner}
     */
    private function resolve(Request $request, string $token): array
    {
        $list = Wishlist::query()
            ->where('share_token', $token)
            ->where('visibility', '!=', 'private')
            ->first();

        if ($list === null) {
            throw new NotFoundHttpException;
        }

        $viewer = Owner::fromRequest($request);

        abort_unless($this->board->writableBy($list, $viewer), 403);

        return [$list, $viewer];
    }
}
