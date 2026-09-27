<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Market;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the market from the {market} route prefix and binds it for the
 * request.
 *
 * Every public route lives under /{market}/ so that a page is unambiguously
 * about one catalogue. This also sets Laravel's app locale, which is a separate
 * concept — the market decides the catalogue, its language decides the strings.
 */
class SetMarket
{
    public function handle(Request $request, Closure $next): Response
    {
        $segment = $request->route('market');
        $market = is_string($segment) ? Market::tryFrom($segment) : null;

        // The route pattern constrains {market} to known values, so reaching
        // here with an unknown one means a route was registered without the
        // constraint. Fall back rather than 500.
        $market ??= Market::default();

        app()->instance(CurrentMarket::class, new CurrentMarket($market));
        app()->setLocale($market->language());

        // Clear any SEO metadata left over from a previous request. Under a
        // persistent runtime this object outlives the request, and a leaked
        // JSON-LD block would advertise the wrong product's price on someone
        // else's page.
        app(PageMeta::class)->reset();

        // There was a `setlocale(LC_TIME, …)` here until 2026-09-27. Nothing
        // read it (no strftime, no LC_TIME-dependent call; dates and numbers are
        // formatted by Carbon's translator and in the browser), and setlocale()
        // is process-wide: under FrankenPHP's threads, and worse under worker
        // mode, one request's market would change another's in flight. Formatting
        // that follows the market belongs to the formatter, given the market.

        // Caches and CDNs must not serve a Dutch page to a French visitor.
        $response = $next($request);
        $response->headers->set('Content-Language', $market->hrefLang());

        return $response;
    }
}
