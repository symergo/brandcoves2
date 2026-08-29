<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Source;
use App\Services\Wishlist\PendingSave;
use App\Support\CurrentMarket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Sign in to keep this" — without losing the this.
 *
 * Unauthenticated on purpose: the whole point is that it is reached by somebody
 * who has no account yet. It writes to their session and to nothing else, so
 * the worst an abusive caller achieves is filling their own session with a
 * product they will then be asked to sign in for.
 *
 * The save itself still happens on `POST /list-items`, behind `auth`, through
 * the same `ItemSaver` as every other path. Nothing here shortens that.
 */
class SaveIntentController extends Controller
{
    public function store(Request $request, CurrentMarket $current, PendingSave $pending): JsonResponse
    {
        /*
         * The same shape `WishlistItemController::store()` accepts, minus the
         * routing fields.
         *
         * Deliberately no `wishlist_id`, `new_list` or `new_recipient`: a
         * pending save lands in the default list, and carrying a chosen
         * destination across a sign-in would mean validating ownership of a
         * list against an account that did not exist when it was chosen.
         *
         * `manual` is excluded because a hand-written wish is typed on a list
         * page, which is behind `auth` already — there is no signed-out path to
         * it and accepting one here would be a free-text channel with no owner.
         */
        $validated = $request->validate([
            'group_id' => ['nullable', 'integer', 'required_without:source'],
            'source' => [
                'nullable',
                'string',
                'in:'.implode(',', array_diff(Source::values(), [Source::Manual->value])),
                'required_without:group_id',
            ],
            'external_id' => ['nullable', 'string', 'max:190', 'required_with:source'],
            'title' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'url', 'max:1024'],
            'price' => ['nullable', 'integer', 'min:0'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        $pending->remember(
            payload: array_diff_key($validated, ['return_to' => null]),
            market: $current->get(),
            returnTo: $this->safeReturnTo($validated['return_to'] ?? null),
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Where to put them back, if it is somewhere on this site.
     *
     * This value is chosen by the caller, and it ends up in `url.intended` —
     * which is what `redirect()->intended()` sends a freshly signed-in person
     * to. An unchecked value there is an open redirect wearing a login page as
     * a costume: follow a link, sign in for real, and be handed to somebody
     * else's host with the trust of having just authenticated.
     *
     * So: a path on this host, or nothing. A leading `//` is rejected because
     * `//evil.example` is a protocol-relative URL and not a path at all, and a
     * backslash because browsers have historically normalised it to a slash.
     */
    private function safeReturnTo(?string $path): ?string
    {
        if ($path === null || ! str_starts_with($path, '/')) {
            return null;
        }

        if (str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        return $path;
    }
}
