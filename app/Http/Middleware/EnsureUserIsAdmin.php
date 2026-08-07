<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // 404 rather than 403: admin tooling should not confirm its own
        // existence to someone who has no business there.
        abort_unless($request->user()?->is_admin === true, 404);

        return $next($request);
    }
}
