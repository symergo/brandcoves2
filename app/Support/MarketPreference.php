<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Market;
use App\Http\Controllers\MarketPreferenceController;
use App\Http\Middleware\TrackAnonymousIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie as CookieFactory;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The market a visitor *chose*, as opposed to the one we guessed for them.
 *
 * ## Why a cookie exists at all
 *
 * `/` negotiates from `Accept-Language`, which is the browser's language list
 * and nothing else — no geolocation, no account setting. That is a reasonable
 * first guess and a terrible permanent answer: a Belgian machine whose browser
 * language is plain "Nederlands" reports `nl-NL`, so it lands on the Dutch
 * catalogue, and before this cookie existed nothing remembered the correction.
 * Clicking the Belgian flag worked for exactly as long as the visitor stayed on
 * the page; the next visit to the bare domain re-ran the same negotiation and
 * sent them straight back. The switcher looked broken because its effect had no
 * memory.
 *
 * ## Only an explicit choice is recorded
 *
 * Written by {@see MarketPreferenceController} and by
 * nothing else — in particular *not* by `SetMarket`, which would have been a
 * one-line change and the wrong one. `SetMarket` runs on every market page,
 * including one reached by opening a friend's shared `/nl-nl/p/123` link, so
 * writing there would let any link anyone sends you silently repoint your home
 * market. A guess must never be able to promote itself into a preference.
 *
 * ## Lifetime
 *
 * A year, matching {@see TrackAnonymousIdentity}. The
 * expiry does not slide: refreshing it would mean a `Set-Cookie` on every
 * request to save a visitor who has not been back in twelve months one flag
 * click, and that visitor gets re-negotiated to a sensible market anyway.
 */
final class MarketPreference
{
    public const COOKIE = 'bc_market';

    private const LIFETIME_MINUTES = 60 * 24 * 365;

    /**
     * The stored choice, or null if there is none to honour.
     *
     * Re-validated on every read rather than trusted as written. The cookie
     * outlives deploys by a year, so it can name a market that has since been
     * unpublished — and honouring that would pin a visitor to a catalogue with
     * no supply in it, which is the one outcome `isPublished()` exists to
     * prevent. An unhonourable cookie falls through to negotiation rather than
     * erroring: the visitor gets a working market and never learns there was a
     * cookie.
     */
    public static function stored(Request $request): ?Market
    {
        $value = $request->cookie(self::COOKIE);

        if (! is_string($value)) {
            return null;
        }

        $market = Market::tryFrom($value);

        return $market !== null && $market->isPublished() ? $market : null;
    }

    /**
     * The market bar above the header: whether to show it, and what it says.
     *
     * Replaced a dialog that covered the page on a first visit (2026-09-26):
     * a phone showed "Where are you?" before anything could be read. The bar
     * sits in the flow above the header, so the page is readable around it
     * and ignoring it is allowed. Components/MarketBar draws it.
     *
     * Null, no bar, when:
     * - the visitor is a crawler: it keeps no cookie, so the bar would sit in
     *   every page it indexes;
     * - the URL carries no market (the market shown was not read from it);
     * - a choice is on file and this page is in that country. A language
     *   difference within one country (be-nl read by a be-fr visitor) is not
     *   worth a bar: it is the same shops.
     *
     * Otherwise two fields:
     * - `suggest`: a market to offer first. The stored choice when this page
     *   is in another country (a friend's shared `/nl-nl/...` link opened by
     *   a Belgian), or, with nothing stored, the market the browser language
     *   points at when that is not the country on screen.
     * - `remember`: whether closing the bar records this page's market as the
     *   choice. Only when nothing is stored *and* this page is the country we
     *   would have guessed anyway, so closing means "yes, that is right". On a
     *   link to another country it only hides the bar: closing a bar is not
     *   saying "this is my home", and letting it write would be exactly the
     *   silent repointing the switcher rule forbids.
     *
     * Every write still goes through MarketPreferenceController, the
     * switcher's POST; this only decides what the bar offers.
     *
     * @return array{suggest: string|null, remember: bool}|null
     */
    public static function bar(Request $request, Market $current): ?array
    {
        if (Crawlers::looksLikeOne($request->userAgent())) {
            return null;
        }

        if ($request->route()?->hasParameter('market') !== true) {
            return null;
        }

        $stored = self::stored($request);

        if ($stored !== null) {
            return $stored->country() === $current->country()
                ? null
                : ['suggest' => $stored->value, 'remember' => false];
        }

        $guess = Market::fromAcceptLanguage($request->header('Accept-Language'));
        $guessedThisCountry = $guess->country() === $current->country();

        return [
            'suggest' => $guessedThisCountry ? null : $guess->value,
            'remember' => $guessedThisCountry,
        ];
    }

    /**
     * The market to send this request to when its URL does not say.
     *
     * The one entry point for that question — root redirect, legacy 404 mapper
     * and the guest/auth redirects all ask it here, so a visitor who chose
     * Belgium gets Belgium from them rather than only from the homepage.
     *
     * Known gap, accepted: called from the 404 handler this only ever sees the
     * header. A 404 is thrown by the router before the `web` group runs, so
     * `EncryptCookies` has not decrypted anything and `stored()` reads
     * ciphertext, fails `tryFrom` and returns null. That degrades to exactly the
     * pre-cookie behaviour on inbound v1 links, which is not worth
     * hand-decrypting against `CookieValuePrefix` to fix. See
     * docs/features/market-routing.md.
     */
    public static function resolve(Request $request): Market
    {
        return self::stored($request)
            ?? Market::fromAcceptLanguage($request->header('Accept-Language'));
    }

    /**
     * The cookie recording a choice.
     *
     * `httpOnly`: no client code reads this — the current market already
     * arrives as an Inertia prop, so exposing it to JS would add a second copy
     * of one fact and no capability. Encrypted by `EncryptCookies` like every
     * other cookie here, which costs nothing and keeps the set uniform.
     */
    public static function cookie(Market $market): Cookie
    {
        return CookieFactory::make(
            name: self::COOKIE,
            value: $market->value,
            minutes: self::LIFETIME_MINUTES,
            httpOnly: true,
            sameSite: 'lax',
        );
    }
}
