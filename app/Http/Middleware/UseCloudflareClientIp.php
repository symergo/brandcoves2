<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The visitor's address, as Cloudflare saw it.
 *
 * Until 2026-10-25 every request reached the app from Traefik's address
 * (10.0.2.2) with no X-Forwarded-For, so `$request->ip()` was the same for
 * everybody: every `throttle:` limit was one bucket shared by the whole
 * world, and a crawler doing 30 searches a minute spent half of it for
 * everyone. giftcoves.com went behind Cloudflare that day, and Cloudflare
 * puts the real address in `CF-Connecting-IP`.
 *
 * Trusted without checking that the request came through Cloudflare, which
 * the app cannot see from behind Traefik. A request sent straight to the
 * VPS can therefore claim any address. That costs no more than before: all it
 * lets a caller do is pick their own throttle bucket, which they could
 * already empty for everyone. Closing it means firewalling the origin to
 * Cloudflare's ranges (docs/deployment.md).
 *
 * Global and first, so nothing reads the address before it is set. The
 * forwarded-for header is dropped with it: trustProxies('*') would otherwise
 * prefer whatever that header says.
 */
class UseCloudflareClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = trim((string) $request->headers->get('CF-Connecting-IP'));

        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $request->server->set('REMOTE_ADDR', $ip);
            $request->headers->remove('X-Forwarded-For');
        }

        return $next($request);
    }
}
