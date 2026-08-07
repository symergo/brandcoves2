<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Http\Controllers\ClickBeaconController;
use App\Http\Controllers\ClickOutController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Unprefixed
|--------------------------------------------------------------------------
*/

// Deployment health. Reports the commit that built the image and the last
// migration applied — Coolify's healthcheck target, and the first thing to
// check after a deploy.
Route::get('/health', HealthController::class)->name('health');

// Sitemaps and robots. Unprefixed: crawlers look for them at the root, and a
// per-market copy would just be five competing files.
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemap/{market}/{page}.xml', [SitemapController::class, 'market'])
    ->whereNumber('page')
    ->name('sitemap.market');

// Root sends visitors to their best-guess market. A 302, never a 301: the guess
// is based on a request header and must not be cached into permanence.
Route::get('/', function () {
    $market = Market::fromAcceptLanguage(request()->header('Accept-Language'));

    return redirect('/'.$market->value, 302);
})->name('root');

/*
|--------------------------------------------------------------------------
| Market-scoped
|--------------------------------------------------------------------------
|
| Every public page lives under /{market}/ so a URL is unambiguously about one
| catalogue. The pattern constraint means an unknown market 404s at the router
| rather than reaching a controller with a bad value.
*/

Route::pattern('market', implode('|', array_map('preg_quote', Market::values())));

Route::prefix('{market}')->group(function () {
    Route::get('/', HomeController::class)->name('home');

    Route::get('/search', SearchController::class)->name('search');

    // The slug is decoration; the id is identity. A stale slug redirects rather
    // than 404s, so old shared links keep working after a retitle.
    Route::get('/p/{group}/{slug?}', ProductController::class)
        ->whereNumber('group')
        ->name('product');

    // Every outbound link goes through here: one place validates the scheme of
    // a third-party URL before it becomes a Location header, and records the
    // click. Rate-limited because it is an unauthenticated redirector.
    Route::get('/go/{offer}', ClickOutController::class)
        ->whereNumber('offer')
        ->middleware('throttle:60,1')
        ->name('go');

    // Click tracking for links that must be direct anchors (Amazon requires
    // unobscured Associates links). Fire-and-forget: the browser reports the
    // click, and losing one never affects the visitor's navigation.
    Route::post('/track/click', ClickBeaconController::class)
        ->middleware('throttle:120,1')
        ->name('click.beacon');

    // Phase 3-6 routes land here:
    //   /{market}/gift                       Gift Whisperer wizard
    //   /{market}/lists                      wishlists
    //   /{market}/l/{shareToken}             shared list, claimable
    //   /{market}/scan                       mobile barcode scanner
    //   /{market}/daily                      today's picks
    //   /{market}/guides                     buying guides
});
