<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route on one of the token's abilities.
 *
 * The point of splitting write from publish: a key that drafts is a key whose
 * worst day produces something a person has to read and delete. A key that
 * publishes is a key whose worst day is on the site. Most automation wants the
 * first one, and it only gets to want it if the two are separate routes.
 */
class RequireApiAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = AuthenticateApiToken::from($request);

        if ($token === null || ! $token->can($ability)) {
            return response()->json([
                'message' => "This token does not have the '{$ability}' ability.",
                'granted' => $token?->abilities ?? [],
            ], 403);
        }

        return $next($request);
    }
}
