<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Seo\LegacyRedirects;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use App\Support\MarketPreference;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page a visitor gets when the address does not exist.
 *
 * ## Why it is a real page and not the framework's
 *
 * Laravel's stock 404 is a grey box with a number in it. Somebody reaches it
 * holding an address that used to work — an old bookmark, a link from a forum,
 * a guide we stopped publishing — and the grey box tells them the site is
 * broken. It is the one page guaranteed to be seen by a person who wanted
 * something specific, so it should offer the next best thing rather than a
 * status code.
 *
 * This became concrete on 2026-09-06: 61 buying guides were retired at once
 * because they had no article in them (see `docs/features/cove-planner.md`).
 * Their addresses were published for weeks, so they will be followed for
 * months.
 *
 * ## It renders through the ordinary page pipeline
 *
 * Reached two ways, and both matter:
 *
 * - **A route matched and the controller gave up** — `/be-nl/guides/whatever`.
 *   The exception handler in `bootstrap/app.php` calls `page()` here.
 * - **No route matched at all** — a typo, or a crawler guessing. Two
 *   `Route::fallback()` entries send those here instead, so they run the web
 *   middleware and arrive with a market, a language and the header and footer
 *   every other page has. Rendering an Inertia page straight from the exception
 *   handler cannot do that: an unmatched URL never reaches the middleware, so
 *   `CurrentMarket` is unbound and the shared props the layout reads are absent.
 *
 * ## The old site is still checked first
 *
 * v1 was a WordPress site with thousands of indexed paths, and
 * `LegacyRedirects` maps the ones worth keeping. That check used to sit in the
 * exception handler, which was the only thing an unmatched URL reached; now
 * that a fallback route catches those first, the check has to travel with it or
 * every v1 address would quietly start answering "not found" instead of
 * redirecting. Both entry points ask, so neither can lose it.
 */
class NotFoundController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $destination = app(LegacyRedirects::class)->urlFor(
            $request->path(),
            MarketPreference::resolve($request),
        );

        // 301, not 302: the move is permanent, and a 302 tells a crawler to
        // keep the old address indexed.
        if ($destination !== null) {
            return redirect()->away($destination, 301);
        }

        return $this->page($request);
    }

    /**
     * The page itself, at a genuine 404.
     *
     * The status matters as much as the words. A "helpful" 404 served as 200 is
     * a soft 404: crawlers index it, and every dead address on the site becomes
     * a duplicate of one page competing with the real ones.
     */
    public function page(Request $request): Response
    {
        $current = app(CurrentMarket::class);

        app(PageMeta::class)->set(
            title: __('site.not_found.seo_title'),
            description: __('site.not_found.seo_description'),
            canonical: url($request->path()),
            // Never indexed. There is nothing here to find, and a 404 that
            // invites crawling gets crawled.
            robots: 'noindex, follow',
        );

        return Inertia::render('Errors/NotFound', [
            'urls' => [
                'home' => $current->url(),
                'search' => $current->url('search'),
                'gift' => $current->url('gift'),
                'daily' => $current->url($current->get()->coveSegment()),
                'guides' => $current->url('guides'),
                'surprise' => $current->url('surprise'),
                'popular' => $current->url('popular-searches'),
                'brands' => $current->url('brands'),
                'shops' => $current->url('shops'),
                'lists' => $current->url('lists'),
            ],
        ])->toResponse($request)->setStatusCode(404);
    }
}
