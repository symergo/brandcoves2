<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\CookieConsentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie as CookieFactory;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Whether this visitor has agreed to the analytics cookie.
 *
 * ## Why this exists at all
 *
 * Everything else this site stores is strictly necessary for something the
 * visitor asked for — the session, the CSRF token, the market they chose, the
 * identifier that lets a list work before there is an account — and Article
 * 5(3) of the ePrivacy Directive exempts exactly that category. Google
 * Analytics is the first thing here that falls outside it: `_ga` is a
 * first-party cookie set for measurement, which is a purpose the visitor gets
 * to refuse. So the tag does not render until they have said yes.
 *
 * The gate is the *cookie*, read server-side, rather than a flag the client
 * checks after the fact. A tag that loads and then decides is a tag that has
 * already contacted Google and already read whatever it was going to read.
 *
 * ## Three states, not two
 *
 * `null` is not "no". It means nobody has been asked, which is the only state
 * that shows the banner. A refusal is written down like an acceptance is,
 * because otherwise every page load re-asks a visitor who has already declined
 * — which is both hostile and, under the EDPB's reading, a way of nagging
 * somebody into a consent that is no longer freely given.
 *
 * ## Lifetime
 *
 * Six months, both ways. Long enough that a regular visitor is not asked
 * again on every trip; short enough that consent is a decision that expires
 * rather than one made once in 2026 and honoured forever. This is the CNIL's
 * recommended cadence and the one most of the industry has settled on.
 * Deliberately shorter than {@see MarketPreference}'s year: a market choice is
 * a convenience the visitor benefits from, and consent is a permission we
 * benefit from.
 *
 * Written by {@see CookieConsentController} and by nothing else, for the same
 * reason `bc_market` is: a preference must only ever be recorded by the
 * control that exists to record it.
 */
final class CookieConsent
{
    public const COOKIE = 'bc_consent';

    public const GRANTED = 'granted';

    public const DENIED = 'denied';

    private const LIFETIME_MINUTES = 60 * 24 * 182;

    /**
     * `true` accepted, `false` declined, `null` never asked.
     */
    public static function stored(Request $request): ?bool
    {
        $value = $request->cookie(self::COOKIE);

        return match ($value) {
            self::GRANTED => true,
            self::DENIED => false,
            // Anything else — a truncated cookie, a value from an older
            // encoding — is treated as unasked rather than as either answer.
            // Guessing "yes" would be a consent nobody gave; guessing "no"
            // silently would hide the banner that lets them fix it.
            default => null,
        };
    }

    /**
     * The string form for the Inertia payload: what the banner branches on.
     */
    public static function state(Request $request): ?string
    {
        return match (self::stored($request)) {
            true => self::GRANTED,
            false => self::DENIED,
            null => null,
        };
    }

    public static function cookie(bool $granted): Cookie
    {
        return CookieFactory::make(
            self::COOKIE,
            $granted ? self::GRANTED : self::DENIED,
            self::LIFETIME_MINUTES,
        );
    }

    /**
     * Undo. Clearing the cookie returns the visitor to "never asked", which is
     * what the footer's Cookies link does — a withdrawal has to be as easy as
     * the acceptance was, and the easiest honest thing is to put the question
     * back.
     */
    public static function forget(): Cookie
    {
        return CookieFactory::forget(self::COOKIE);
    }
}
