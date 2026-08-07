<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
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

    // Phase 2-6 routes land here:
    //   /{market}/search                     search + offer comparison
    //   /{market}/p/{group}/{slug}           product page, all offers
    //   /{market}/gift                       Gift Whisperer wizard
    //   /{market}/lists                      wishlists
    //   /{market}/l/{shareToken}             shared list, claimable
    //   /{market}/daily                      today's picks
    //   /{market}/daily/{date}/{slug}        permanent pick page
    //   /{market}/guides                     buying guides
    //   /{market}/go/{offer}                 click-out redirector
});
