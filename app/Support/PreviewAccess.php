<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Seeing a page before it is published.
 *
 * Editorial here is written by a model and finished by a person, and until now
 * the only way to find out what a guide or an edition actually looked like was
 * to publish it and read the live page. That makes the first reader of every
 * piece a member of the public, and the only way to fix a bad one is to
 * unpublish something people may already have opened.
 *
 * ## Two ways in, for two different people
 *
 * **Signed in as an admin.** The ordinary case: someone editing a draft in the
 * admin wants to see it. No link to mint, nothing to expire.
 *
 * **A signed link.** The case that matters more, because the person whose
 * opinion you want on the prose usually does not have an admin account — a
 * colleague, a native speaker checking the Dutch. A temporary signed URL lets
 * them read the draft without one, and expires on its own rather than becoming
 * a permanent hole into unpublished work.
 *
 * Signing the *existing* route rather than adding a `/preview/...` one keeps a
 * promise that matters: what the reviewer sees is the real page, rendered by
 * the real controller. A separate preview route is a second implementation of
 * the page, and it drifts.
 */
final class PreviewAccess
{
    /** Long enough to get an answer, short enough not to be a standing key. */
    private const LIFETIME_DAYS = 7;

    /**
     * May this request see unpublished content?
     *
     * The signature check comes second because it is the more expensive of the
     * two and the admin case is the common one.
     */
    public static function allowed(Request $request): bool
    {
        if ($request->user()?->is_admin === true) {
            return true;
        }

        /*
         * `hasValidSignature()` covers the full URL including the query, so a
         * link signed for one guide cannot be edited into a link for another —
         * which is the whole reason this is a signature rather than a token
         * parameter somebody could guess or share sideways.
         */
        return $request->query('preview') !== null && $request->hasValidSignature();
    }

    /**
     * A link that lets somebody without an account read a draft.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function link(string $route, array $parameters): string
    {
        return URL::temporarySignedRoute(
            $route,
            now()->addDays(self::LIFETIME_DAYS),
            [...$parameters, 'preview' => 1],
        );
    }
}
