<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Wishlist\PendingClaim;
use App\Support\ReturnPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Sign in to say you'll get this" — without losing the this.
 *
 * The sibling of {@see SaveIntentController}, unauthenticated for the same
 * reason: it exists for somebody who has no account yet. It writes to the
 * caller's own session and to nothing else, so the worst an abusive caller
 * achieves is filling their own session with an item they will then be asked to
 * sign in for. Nothing is claimed here — the claim still happens in
 * `SharedListController::claim()`, or on the sign-in that follows, behind the
 * same guards as every other claim.
 *
 * Note what is *not* validated: that the token names a real list, or the item a
 * real row. Answering that question to an anonymous caller would turn this into
 * an oracle for guessing share tokens, and it buys nothing — `PendingClaim`
 * re-resolves both at replay time and drops the intent if either has gone.
 */
class ClaimIntentController extends Controller
{
    public function store(Request $request, PendingClaim $pending): JsonResponse
    {
        $validated = $request->validate([
            // A share code, not a uuid: ten characters from ShareCode's
            // alphabet. The rule stays loose because PendingClaim re-resolves
            // the token at replay time and drops it if no list matches — a
            // strict format here would only tell a caller what a valid token
            // looks like.
            'token' => ['required', 'string', 'max:64'],
            'item' => ['required', 'integer'],
            // Optional, and kept only if the list turns out to show names.
            'display_name' => ['nullable', 'string', 'max:80'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        $pending->remember(
            token: $validated['token'],
            itemId: (int) $validated['item'],
            name: filled($validated['display_name'] ?? null) ? trim((string) $validated['display_name']) : null,
            returnTo: ReturnPath::safe($validated['return_to'] ?? null),
        );

        return response()->json(['ok' => true]);
    }
}
