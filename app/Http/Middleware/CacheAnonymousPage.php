<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Market;
use App\Support\ContributeBar;
use App\Support\CookieConsent;
use App\Support\MarketPreference;
use App\Support\SsrGateway;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie as CookieJar;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The finished page, kept a few minutes for visitors who are not signed in.
 *
 * Layer 3 of the speed plan (docs/features/speed.md, "Anonymous page cache").
 * A public page (a Cove, a brand, a product) is the same for every signed-out
 * visitor, and a crawler or a first visit is most of the traffic, so the HTML
 * (or the Inertia JSON) is built once and handed out for five minutes. Anybody
 * who changes something is signed in, and a signed-in visitor never gets a
 * cached page, so nobody waits five minutes to see their own change.
 *
 * ## Opt-in, per route
 *
 * `->middleware('page-cache')` in routes/web.php, on the public pages only.
 * Lists, claims, accounts, `/for`, Santa, the quiz, search, Discover and every
 * form are not opted in, and PageCacheRoutesTest walks the route table to keep
 * it that way: a cached list page would show one visitor what another claimed.
 *
 * ## Who gets one
 *
 * A GET or HEAD with no session cookie, no remember-me cookie and nobody
 * signed in. Without a session there is no flash message and no validation
 * error to leak. Only a 200 is stored, only when the page set no cookie of its
 * own, and never when the server-side render failed (a page without its SSR
 * HTML would be handed to every crawler for five minutes).
 *
 * ## Such a visitor gets no cookie at all
 *
 * The request that is eligible has its session switched to the in-memory
 * driver before StartSession runs, so nothing is written to Redis, and the
 * session and XSRF-TOKEN cookies are taken off the response. The identity
 * cookie (`bc_visitor`) is not made either (TrackAnonymousIdentity skips an
 * eligible request). That is what makes the page the same for everybody, and
 * a response with no Set-Cookie is also one a browser may keep.
 *
 * Why this rather than taking StartSession off these routes with
 * `withoutMiddleware`: the same route serves a signed-in visitor, who needs the
 * session, and a guest who already has one (they saved something, so flash
 * messages and adding mode live there). Middleware is fixed per route, not per
 * request; this decides per request. It runs before StartSession
 * (bootstrap/app.php puts it there in the priority list), so a hit starts no
 * session, runs no query and never reaches the controller.
 *
 * The page's CSRF token is the one per-visitor thing left in the HTML. For an
 * eligible request app.blade.php leaves the meta tag empty, and the browser
 * asks `GET /csrf` for a token right before its first write
 * (resources/js/http.ts, `ensureCsrfToken()`). That request starts the session.
 *
 * ## The key
 *
 * Host and path; the query string, but only parameters the pages read
 * (anything else is a bypass, so `?x=<random>` cannot fill the cache with
 * copies); the market; whether it is an Inertia request and every X-Inertia
 * header it carries (the asset version among them, so a browser on an old
 * build never receives a cached JSON page and misses the 409 that reloads it);
 * the deployed commit and the asset version; and the per-guest parts of the
 * shared props: the market bar, the contribute bar and the cookie-consent
 * answer. Those three are computed here exactly as HandleInertiaRequests
 * computes them, so the key holds the answer rather than the cookies that led
 * to it. A shared prop that starts to differ per guest must be added to
 * {@see self::variant()}, or a guest will see another guest's version of it.
 *
 * ## Fresh, stale, rebuilt
 *
 * Fresh for five minutes. Kept an hour. A request that finds a stale copy
 * tries a lock: the one that gets it rebuilds the page and stores it, every
 * other request meanwhile gets the stale copy at once.
 *
 * `X-Page-Cache` says which of hit, miss, stale or bypass happened.
 *
 * The stored entry is a plain array (body, status, headers): the cache refuses
 * to rebuild objects (`serializable_classes => false`, config/cache.php).
 */
final class CacheAnonymousPage
{
    public const ALIAS = 'page-cache';

    /** Request attribute: this request is served as an anonymous, cacheable page. */
    public const ELIGIBLE = 'page_cache.eligible';

    public const HEADER = 'X-Page-Cache';

    /** Bump to retire every stored page at once when the entry's shape changes. */
    private const VERSION = 'v1';

    /** Long enough for one page to build; a crashed rebuild frees it on its own. */
    private const LOCK_SECONDS = 30;

    /**
     * The query parameters a cached page may carry, with the values it may
     * carry in them. Anything else is a bypass, and so is an array value.
     *
     * Bounded on purpose. Each distinct key is a stored page of up to a few
     * hundred KB, so a parameter that takes free text (`q`, a price) would let
     * anyone fill the cache with copies by varying it. Those pages are still
     * served, just not from the cache. Tracking parameters (utm_*, gclid) are
     * a bypass too, not ignored: the Inertia page object carries the full URL,
     * so a page stored for one visitor's gclid would put it in the next
     * visitor's address bar.
     *
     * @var array<string, string>
     */
    private const KNOWN_PARAMS = [
        'page' => '/^[1-9][0-9]{0,3}$/',
        'sort' => '/^[a-z0-9_-]{1,40}$/',
        'view' => '/^[a-z0-9_-]{1,40}$/',
        'budget' => '/^[0-9]{0,6}-[0-9]{0,6}$/',
        'for' => '/^[a-z0-9_-]{1,40}$/',
        'interest' => '/^[a-z0-9_-]{1,40}$/',
        'occasion' => '/^[a-z0-9_-]{1,40}$/',
        'in_stock' => '/^(0|1|true|false)$/',
        'discounted' => '/^(0|1|true|false)$/',
        'comparable' => '/^(0|1|true|false)$/',
    ];

    /**
     * Response headers that belong to one response, not to the page: never
     * stored. Set-Cookie above all.
     */
    private const UNSTORED_HEADERS = [
        'set-cookie', 'date', 'cache-control', 'expires', 'pragma', 'age',
        'x-ratelimit-limit', 'x-ratelimit-remaining', 'retry-after', 'x-page-cache',
    ];

    /** Whether this request is being served as a cacheable anonymous page. */
    public static function servesAnonymously(?Request $request): bool
    {
        return $request?->attributes->get(self::ELIGIBLE) === true;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->key($request);

        if ($key === null) {
            return $this->mark($next($request), 'bypass');
        }

        $request->attributes->set(self::ELIGIBLE, true);

        /*
         * An in-memory session for this one request: StartSession still runs
         * (the CSRF check, the Inertia shared props and the 404 page all read
         * a session), but nothing is written to Redis for a visitor who will
         * never send the cookie back, and the cookie itself is removed below.
         */
        config(['session.driver' => 'array']);

        $entry = $this->read($key);

        if ($entry !== null && $this->isFresh($entry)) {
            return $this->serve($entry, 'hit');
        }

        if ($entry !== null) {
            $lock = Cache::lock($key.':lock', self::LOCK_SECONDS);

            if (! $lock->get()) {
                return $this->serve($entry, 'stale');
            }

            try {
                return $this->build($request, $next, $key, rebuilding: true);
            } finally {
                $lock->release();
            }
        }

        return $this->build($request, $next, $key, rebuilding: false);
    }

    private function build(Request $request, Closure $next, string $key, bool $rebuilding): Response
    {
        // Compared after: a cookie this page queued makes it one visitor's.
        $queued = CookieJar::getQueuedCookies();

        $response = $next($request);

        $this->removeSessionCookies($response);

        if ($this->storable($request, $response, CookieJar::getQueuedCookies() != $queued)) {
            Cache::put($key, [
                'at' => now()->getTimestamp(),
                'status' => $response->getStatusCode(),
                'headers' => $this->storedHeaders($response),
                'body' => (string) $response->getContent(),
            ], (int) config('giftcoves.page_cache.stale_seconds'));

            return $this->publicHeaders($this->mark($response, 'miss'));
        }

        // The page is gone or broken now (a Cove unpublished, a product no
        // longer carried): the stale copy must not outlive it.
        if ($rebuilding) {
            Cache::forget($key);
        }

        return $this->mark($response, 'miss');
    }

    /**
     * The cache key, or null when this request is not one to cache.
     */
    private function key(Request $request): ?string
    {
        if (! config('giftcoves.page_cache.enabled')) {
            return null;
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return null;
        }

        if ($request->cookies->has((string) config('session.cookie'))) {
            return null;
        }

        foreach (array_keys($request->cookies->all()) as $name) {
            if (str_starts_with((string) $name, 'remember_')) {
                return null;
            }
        }

        // hasUser(), not user(): it asks without reading a session that has
        // not started yet. Only a test's actingAs() can get here signed in.
        if (Auth::guard()->hasUser()) {
            return null;
        }

        $segment = $request->route('market');
        $market = is_string($segment) ? Market::tryFrom($segment) : null;

        if ($market === null) {
            return null;
        }

        $query = $this->query($request);

        if ($query === null) {
            return null;
        }

        return 'page:'.self::VERSION.':'.hash('xxh128', (string) json_encode([
            $request->getSchemeAndHttpHost(),
            '/'.ltrim($request->path(), '/'),
            $query,
            $market->value,
            $this->inertiaHeaders($request),
            (string) config('giftcoves.commit_sha'),
            app(HandleInertiaRequests::class)->version($request),
            $this->variant($request, $market),
        ]));
    }

    /**
     * The known parameters, sorted by name; null when any parameter is unknown
     * or carries a value outside its pattern.
     *
     * @return array<string, string>|null
     */
    private function query(Request $request): ?array
    {
        $query = $request->query->all();

        foreach ($query as $name => $value) {
            $pattern = self::KNOWN_PARAMS[$name] ?? null;

            if ($pattern === null || ! is_string($value) || preg_match($pattern, $value) !== 1) {
                return null;
            }
        }

        ksort($query);

        return $query;
    }

    /**
     * Every X-Inertia* header, so an Inertia visit (JSON) never shares an entry
     * with a full load (HTML), a partial reload never shares one with a whole
     * page, and a browser holding the translations (the once-props header)
     * never gets a page stored for one that did not.
     *
     * @return array<string, string>
     */
    private function inertiaHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            if (str_starts_with($name, 'x-inertia')) {
                $headers[$name] = implode(',', $values);
            }
        }

        // Inertia's prefetch sends the same page with one more header.
        $headers['purpose'] = (string) $request->headers->get('Purpose', '');

        ksort($headers);

        return $headers;
    }

    /**
     * The parts of the shared props that differ between signed-out visitors,
     * computed the way HandleInertiaRequests::share() computes them.
     *
     * @return array<string, mixed>
     */
    private function variant(Request $request, Market $market): array
    {
        $marketBar = MarketPreference::bar($request, $market);

        return [
            'marketBar' => $marketBar,
            'contributeBar' => ContributeBar::shows($request, $marketBar !== null),
            'consent' => CookieConsent::state($request),
        ];
    }

    /**
     * @return array{at: int, status: int, headers: array<string, list<string>>, body: string}|null
     */
    private function read(string $key): ?array
    {
        $entry = Cache::get($key);

        if (! is_array($entry) || ! is_int($entry['at'] ?? null) || ! is_string($entry['body'] ?? null)) {
            return null;
        }

        /** @var array{at: int, status: int, headers: array<string, list<string>>, body: string} $entry */
        return $entry;
    }

    /**
     * @param  array{at: int}  $entry
     */
    private function isFresh(array $entry): bool
    {
        return now()->getTimestamp() - $entry['at'] < (int) config('giftcoves.page_cache.fresh_seconds');
    }

    /**
     * @param  array{at: int, status: int, headers: array<string, list<string>>, body: string}  $entry
     */
    private function serve(array $entry, string $how): Response
    {
        $response = new Response($entry['body'], $entry['status'], $entry['headers']);
        $response->headers->set('Age', (string) max(0, now()->getTimestamp() - $entry['at']));

        return $this->publicHeaders($this->mark($response, $how));
    }

    private function storable(Request $request, Response $response, bool $queuedACookie): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        // A cookie the page set on purpose makes it a page for one visitor.
        if ($response->headers->getCookies() !== [] || $queuedACookie) {
            return false;
        }

        if ($request->attributes->get(SsrGateway::FAILED) === true) {
            return false;
        }

        if (! $this->sessionIsEmpty($request)) {
            return false;
        }

        return ! str_contains((string) $response->headers->get('Cache-Control'), 'no-store');
    }

    /**
     * Whether the session holds nothing but what every request puts there.
     *
     * A visitor without a session cookie starts with an empty session, so in
     * practice this is always true. It is the second lock on the door: a flash
     * message, a validation error, or anything a controller put in the session
     * while building the page makes the page one visitor's, and it is not
     * stored. (A controller on an opted-in route that writes the session for a
     * guest loses the write anyway, since the session is in memory; this keeps
     * the page it built out of the cache.)
     */
    private function sessionIsEmpty(Request $request): bool
    {
        if (! $request->hasSession()) {
            return true;
        }

        $session = $request->session();

        if ($session->get('_flash.old', []) !== [] || $session->get('_flash.new', []) !== []) {
            return false;
        }

        return array_diff(array_keys($session->all()), ['_token', '_previous', '_flash']) === [];
    }

    /**
     * The session and XSRF-TOKEN cookies of the in-memory session, and the
     * identity cookie. Every other cookie stays, and keeps the page out of the
     * cache (see storable()).
     */
    private function removeSessionCookies(Response $response): void
    {
        $ours = [(string) config('session.cookie'), 'XSRF-TOKEN', TrackAnonymousIdentity::COOKIE];

        foreach ($response->headers->getCookies() as $cookie) {
            if (in_array($cookie->getName(), $ours, true)) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function storedHeaders(Response $response): array
    {
        $headers = [];

        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            if (! in_array(strtolower($name), self::UNSTORED_HEADERS, true)) {
                $headers[$name] = array_values(array_map('strval', $values));
            }
        }

        return $headers;
    }

    /**
     * What a browser may do with the page. A minute as it is, ten more minutes
     * while it checks in the background.
     *
     * Vary names everything the page differs on that a browser sends, so a
     * browser never reuses the signed-out copy once it holds a session cookie
     * (it signed in, or saved something), or after its market choice changed.
     */
    private function publicHeaders(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
        $response->setVary(array_values(array_unique([
            ...$response->getVary(),
            'X-Inertia', 'X-Inertia-Version', 'X-Inertia-Partial-Data', 'Cookie', 'Accept-Language',
        ])));

        return $response;
    }

    private function mark(Response $response, string $how): Response
    {
        $response->headers->set(self::HEADER, $how);

        return $response;
    }
}
