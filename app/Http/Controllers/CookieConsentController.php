<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\CookieConsent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Records the answer to the cookie banner.
 *
 * ## Why POST, and why unprefixed
 *
 * The same two reasons as {@see MarketPreferenceController}. A GET would be a
 * URL that grants analytics consent on behalf of whoever clicks it, which is
 * the shape of thing that gets pasted into a chat — as a POST it needs a CSRF
 * token, so only this site's own banner can spend one. And the market is not
 * part of the question: consent is about this visitor, not this catalogue.
 *
 * ## Why `back()`
 *
 * Answering the banner must not move the visitor. The market switcher redirects
 * because changing market is a navigation; answering a question about cookies
 * is not, and Inertia posts this with `preserveScroll` so the page does not
 * even move under them. The redirect exists only to carry the `Set-Cookie`.
 */
class CookieConsentController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'choice' => ['required', 'string', 'in:'.CookieConsent::GRANTED.','.CookieConsent::DENIED.',reset'],
        ]);

        $response = back(303);

        // `reset` is the footer's Cookies link: it clears the decision so the
        // banner asks again, which is how a visitor withdraws a consent they
        // have already given.
        return $validated['choice'] === 'reset'
            ? $response->withCookie(CookieConsent::forget())
            : $response->withCookie(CookieConsent::cookie($validated['choice'] === CookieConsent::GRANTED));
    }
}
