<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send the old domain to the new one, path intact.
 *
 * Both domains are served by the same instance, so this is a host check rather
 * than a Traefik rule: whichever domain Coolify has attached, the app is the one
 * thing guaranteed to see the request.
 *
 * It runs **globally and before routing**, not from the 404 handler where
 * `LegacyRedirects` lives. That one catches v1 WordPress paths, which do not
 * exist here and therefore fail first. `brandcoves.com/be-nl/search` is a
 * perfectly valid route — it would answer 200 with the wrong domain in the
 * address bar and never reach a 404 handler at all.
 */
class RedirectLegacyHost
{
    /**
     * Paths that must answer on any host.
     *
     * Coolify's healthcheck reaches the container directly rather than through
     * the domain, so a 301 here reads as an unhealthy container and the deploy
     * rolls back. `/up` is Laravel's own probe, registered in bootstrap/app.php.
     */
    private const ALWAYS_DIRECT = ['health', 'up'];

    /** The WordPress login, its remote-call endpoint and its folders. */
    private const WORDPRESS = '#^(?:wp-login\.php|xmlrpc\.php|wp-(?:admin|content|includes)(?:/|$))#i';

    public function handle(Request $request, Closure $next): Response
    {
        $canonical = (string) config('giftcoves.canonical_host', '');

        // Unset in local and in any environment that has not been cut over, so
        // nothing redirects until somebody deliberately turns it on.
        if ($canonical === '') {
            return $next($request);
        }

        $host = mb_strtolower($request->getHost());

        if (! in_array($host, $this->legacyHosts(), true)) {
            return $next($request);
        }

        if (in_array($request->path(), self::ALWAYS_DIRECT, true)) {
            return $next($request);
        }

        /*
         * WordPress probes end here (2026-11-13). v1 was a WordPress site and
         * its login is still tried, hundreds of times an hour, by scripts
         * guessing passwords. Redirected, each try cost two requests: the 301
         * here and a 404 on the new domain. There is nothing of WordPress to
         * move, so they get that 404 at once, as plain text. Cloudflare blocks
         * the same paths on the new domain; this one is not behind it.
         */
        if (preg_match(self::WORDPRESS, $request->path()) === 1) {
            return response('Not found.', 404, ['Content-Type' => 'text/plain']);
        }

        /*
         * Path and query survive the move.
         *
         * Every indexed URL has an exact equivalent on the new domain, so each
         * one redirects to its own counterpart. Collapsing them onto the
         * homepage instead is the standard way a migration discards the entire
         * index: Google treats a mass redirect to `/` as a soft 404 and drops
         * the ranking rather than transferring it.
         */
        $destination = $request->getScheme().'://'.$canonical.$request->getRequestUri();

        // 301, matching the v1 cutover: the move is permanent, and a 302 tells a
        // crawler to keep the old URL in the index and keep asking for it.
        return redirect()->away($destination, 301);
    }

    /**
     * @return list<string>
     */
    private function legacyHosts(): array
    {
        /** @var list<string> $hosts */
        $hosts = config('giftcoves.legacy_hosts', []);

        return array_map(mb_strtolower(...), $hosts);
    }
}
