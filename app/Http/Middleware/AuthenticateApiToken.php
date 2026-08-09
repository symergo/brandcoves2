<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication for the editorial API.
 *
 * No session, no cookie, no CSRF: the caller is a program with a key, not a
 * browser with a login. That is also why a failure here is a JSON 401 rather
 * than a redirect to /login — a redirect to an HTML page is the least useful
 * possible answer to give an automated client, and it is what the framework
 * does by default.
 */
class AuthenticateApiToken
{
    /** Where the resolved token is parked for the rest of the request. */
    public const ATTRIBUTE = 'api_token';

    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = $request->bearerToken();

        if ($plaintext === null || $plaintext === '') {
            return $this->deny('Missing bearer token.');
        }

        $token = ApiToken::resolve($plaintext);

        if ($token === null) {
            /*
             * One message for unknown, revoked and expired alike.
             *
             * Telling a caller "that key expired" tells an attacker their guess
             * was a real key, which is the one bit of information a brute-force
             * attempt is looking for.
             */
            return $this->deny('Invalid or expired token.');
        }

        $token->touchUsage();

        $request->attributes->set(self::ATTRIBUTE, $token);

        return $next($request);
    }

    /** The token behind the current request, for anything downstream that needs it. */
    public static function from(Request $request): ?ApiToken
    {
        $token = $request->attributes->get(self::ATTRIBUTE);

        return $token instanceof ApiToken ? $token : null;
    }

    private function deny(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 401, [
            'WWW-Authenticate' => 'Bearer',
        ]);
    }
}
