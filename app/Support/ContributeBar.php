<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie as CookieFactory;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The one-line invitation under the header: give feedback, suggest something,
 * vote on what we build (owner, 2026-09-27; docs/features/contribute.md).
 *
 * ## Why the server decides, from a cookie
 *
 * The page is rendered on the server (SSR), and a bar that appears or
 * disappears only after the JavaScript runs moves the whole page by a line
 * after the first paint: exactly for the people who closed it, on every page
 * they open. A cookie the server reads puts the right answer in the first
 * paint. localStorage cannot: the server never sees it.
 *
 * Closing writes the cookie by a POST (ContributeBarController), the same way
 * the market bar's close records a market; nothing else writes it. It is a
 * remembered interface choice and carries nothing about the visitor, which is
 * why it needs no consent (the privacy policy lists it with the necessary
 * cookies). A year, then it may come back once: by then the board has moved.
 *
 * ## When it does not show
 *
 * - The visitor closed it (the cookie).
 * - A crawler: it keeps no cookie, and the bar would sit in every snippet.
 * - While the market bar is up. Two bars stacked over the page on a first
 *   visit is two questions at once, and on a phone that is two lines of
 *   chrome before any content. The market question comes first; this one
 *   appears on the next page after it is answered.
 * - Outside the `/{market}/` pages.
 *
 * Which *pages* skip it (the contribute page itself, sign-in, a flow a
 * non-member was sent into) is the client's call, by page component, because
 * the page is not known when the shared props are built.
 */
final class ContributeBar
{
    public const COOKIE = 'bc_contribute_bar';

    private const CLOSED = 'closed';

    /** A year. Browsers cap cookies at 400 days anyway. */
    private const LIFETIME_MINUTES = 60 * 24 * 365;

    public static function shows(Request $request, bool $marketBarShowing): bool
    {
        if ($marketBarShowing) {
            return false;
        }

        if (Crawlers::looksLikeOne($request->userAgent())) {
            return false;
        }

        if ($request->route()?->hasParameter('market') !== true) {
            return false;
        }

        return $request->cookie(self::COOKIE) !== self::CLOSED;
    }

    /**
     * `httpOnly`: only the server reads it; the page gets the answer as the
     * shared `contributeBar` prop.
     */
    public static function closed(): Cookie
    {
        return CookieFactory::make(
            name: self::COOKIE,
            value: self::CLOSED,
            minutes: self::LIFETIME_MINUTES,
            httpOnly: true,
            sameSite: 'lax',
        );
    }
}
