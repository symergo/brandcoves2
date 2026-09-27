<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AnonymousIdentity;
use App\Models\ListOpen;
use App\Support\Owner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every visitor a durable identity without requiring an account.
 *
 * The gift wizard and the wishlist tray have to be useful before signup —
 * demanding a login before showing results is how you lose the visit. This
 * cookie is what a shortlist, a list and a pick reaction hang off until the
 * visitor creates an account, at which point everything is merged across.
 *
 * The cookie is encrypted and signed by Laravel's EncryptCookies middleware, so
 * a visitor cannot claim someone else's id by editing it.
 */
class TrackAnonymousIdentity
{
    public const COOKIE = 'bc_visitor';

    /** Long-lived on purpose: gift lists are built over weeks, not minutes. */
    private const LIFETIME_MINUTES = 60 * 24 * 365;

    /**
     * Routes that are read by machines, not people, and get no identity.
     *
     * A crawler keeps no cookies, so every fetch of robots.txt, a sitemap
     * chunk or a social card arrived without one and inserted a fresh
     * `anonymous_identities` row — one per product page for a full crawl of
     * the sitemap. The `Set-Cookie` it queued also made those responses
     * uncacheable by any shared cache. Nothing on these routes reads the
     * identity, so they simply skip it. Patterns as `Request::is()` takes them.
     *
     * @var list<string>
     */
    private const MACHINE_PATHS = [
        'robots.txt',
        'sitemap.xml',
        'sitemap/*',
        '*/og/*',
        'health',
        'up',
        'webhooks/*',
        // Pictures on list items. Fetched by an <img> tag, never navigated to.
        'media/*',
    ];

    /**
     * Crawlers and link-preview fetchers, recognised by their User-Agent.
     *
     * The paths above were not enough. On 2026-09-26 production held 2.56
     * million identities, 99% of them seen exactly once: 20,000 to 60,000 new
     * rows a day, because every page a crawler fetches arrives without a cookie
     * and made a row that nothing would ever read. A crawler never saves a
     * list, so it gets no identity, on any path.
     *
     * Fragments, matched case-insensitively. `bot/` and `bot;` rather than a
     * bare "bot", which is also inside ordinary words and phone model names.
     * An empty User-Agent is a script, not a browser.
     *
     * @var list<string>
     */
    private const MACHINE_AGENTS = [
        'bot/', 'bot;', 'bot)', 'crawler', 'spider', 'slurp', 'facebookexternalhit', 'whatsapp',
        'headlesschrome', 'python-requests', 'python-urllib', 'curl/', 'wget/', 'go-http-client',
        'okhttp', 'scrapy', 'httpclient', 'bingpreview', 'lighthouse', 'pingdom', 'uptimerobot',
        // Link unfurlers and Google's URL inspection, none of which says "bot/"
        // where the fragments above look (2026-09-27): "Slackbot-LinkExpanding
        // 1.0", "Slackbot 1.0", "Google-InspectionTool/1.0".
        'slackbot', 'linkexpanding', 'google-inspectiontool',
    ];

    /**
     * Set on a lazy page's request when the visitor has no identity yet but
     * would be given one on their first write. Read by `Owner::canAct()`.
     */
    public const PENDING = 'anonymous_identity_pending';

    /**
     * GET pages that use an existing identity and never create one.
     *
     * Route names, since the paths carry a market and a token. Not cached
     * (CacheAnonymousPage): they are per token and partly per visitor, so a
     * guest still gets a session here, only no identity row and no cookie.
     *
     * @var list<string>
     */
    public const LAZY_ROUTES = [
        'lists.shared',          // /l/{token}
        'gift',                  // Find a gift
        'recipients.self',       // /for/{token}
        'recipients.self.suggest', // /for/{token}/suggest, the same page with a search
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // A signed-in user has a real identity; a second anonymous one would
        // only fragment their data.
        if ($request->user() !== null) {
            return $next($request);
        }

        if ($request->is(...self::MACHINE_PATHS) || self::isMachine((string) $request->userAgent())) {
            return $next($request);
        }

        /*
         * A signed-out visitor reading a public page (a Cove, a brand, a
         * product) gets no identity and no cookie: the page must be the same
         * for everybody so it can be cached (CacheAnonymousPage), and nothing
         * on those pages reads the identity. It is made the moment they first
         * write something, because a write is a POST (or the GET /csrf the
         * browser asks right before one), and every other route still runs
         * the code below.
         */
        if (CacheAnonymousPage::servesAnonymously($request)) {
            return $next($request);
        }

        $id = $request->cookie(self::COOKIE);
        $identity = is_string($id) ? AnonymousIdentity::find($id) : null;

        /*
         * The interactive pages a guest reads before doing anything (a shared
         * list, Find a gift, /for) use an identity the visitor already has,
         * and make none. They are opened from links in chats and emails, so
         * most views are a link preview, a prefetch or somebody who looks and
         * leaves, and each made a row and a cookie that nothing would read.
         * What they offer ("suggest a gift", a vote, the board) is offered to
         * a guest without one on the promise of `Owner::canAct()`: the POST
         * that acts runs this middleware in full and makes the identity then.
         */
        if ($identity === null && self::isLazy($request)) {
            $request->attributes->set(self::PENDING, true);

            return $next($request);
        }

        if ($identity === null) {
            $identity = AnonymousIdentity::create(['last_seen_at' => now()]);
        } elseif ($identity->last_seen_at?->lt(now()->subDay())) {
            // Only once a day: this runs on every request, and a write per
            // page view would be a needless load on the primary.
            $identity->update(['last_seen_at' => now()]);
        }

        $request->attributes->set('anonymous_identity', $identity);

        if ($identity->wasRecentlyCreated) {
            // Shared lists this visitor read before they had an identity, so
            // they still find them under their lists (ListOpen::rememberForLater).
            ListOpen::recordRemembered($request, new Owner(null, $identity));
        }

        $response = $next($request);

        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $identity->getKey(),
            minutes: self::LIFETIME_MINUTES,
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $response;
    }

    /** A read (GET or HEAD) of one of the {@see LAZY_ROUTES}. */
    public static function isLazy(Request $request): bool
    {
        return $request->isMethodSafe()
            && $request->route()?->named(...self::LAZY_ROUTES) === true;
    }

    /** Whether this User-Agent is a crawler or a script rather than a person's browser. */
    public static function isMachine(string $userAgent): bool
    {
        $agent = strtolower(trim($userAgent));

        if ($agent === '') {
            return true;
        }

        foreach (self::MACHINE_AGENTS as $fragment) {
            if (str_contains($agent, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
