<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ContributeBar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Closing the contribute bar, remembered for a year.
 *
 * POST, so the cookie is written only by the bar's own close button (it needs
 * the CSRF token), and unprefixed, like `/market` and `/consent`: it is about
 * this visitor, not about one market's catalogue. The bar posts it by fetch
 * and hides at once, so the answer is a 204 carrying the cookie; a plain form
 * post (no JavaScript) goes back where it came from. See App\Support\ContributeBar.
 */
class ContributeBarController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $response = $request->expectsJson() ? response()->noContent() : back(303);

        return $response->withCookie(ContributeBar::closed());
    }
}
