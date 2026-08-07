<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\ClickBeaconController;
use App\Http\Controllers\ClickOutController;
use App\Http\Controllers\GiftController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RecipientController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SerendipityController;
use App\Http\Controllers\SharedListController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\WishlistItemController;
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

    /*
    |----------------------------------------------------------------------
    | Auth
    |----------------------------------------------------------------------
    |
    | Passwordless. This site holds gift lists and email addresses, not payment
    | details, and a password is a liability people reuse.
    */
    Route::middleware('guest')->group(function () {
        Route::get('/login', [MagicLinkController::class, 'show'])->name('login');
        Route::post('/login', [MagicLinkController::class, 'send'])
            // Rate limited per address and per IP inside the controller too;
            // this is the blunt outer guard.
            ->middleware('throttle:10,1')
            ->name('login.send');

        Route::get('/auth/magic/{token}', [MagicLinkController::class, 'consume'])
            ->middleware('throttle:20,1')
            ->name('login.magic');

        Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('login.google');
        Route::get('/auth/google/callback', [GoogleController::class, 'callback'])
            ->name('login.google.callback');
    });

    Route::post('/logout', [MagicLinkController::class, 'logout'])
        ->middleware('auth')
        ->name('logout');

    /*
    |----------------------------------------------------------------------
    | Wishlists
    |----------------------------------------------------------------------
    |
    | No auth middleware: lists work before signup, keyed on the anonymous
    | cookie identity, and merge into the account at sign-in. Requiring a login
    | to save a product is how you lose the visit.
    */
    Route::get('/lists', [WishlistController::class, 'index'])->name('lists');
    Route::post('/lists', [WishlistController::class, 'store'])->name('lists.store');
    Route::get('/lists/{list}', [WishlistController::class, 'show'])->name('lists.show');
    Route::patch('/lists/{list}', [WishlistController::class, 'update'])->name('lists.update');
    Route::delete('/lists/{list}', [WishlistController::class, 'destroy'])->name('lists.destroy');

    Route::post('/list-items', [WishlistItemController::class, 'store'])->name('items.store');
    Route::patch('/list-items/{item}', [WishlistItemController::class, 'update'])->name('items.update');
    Route::delete('/list-items/{item}', [WishlistItemController::class, 'destroy'])->name('items.destroy');

    Route::post('/recipients', [RecipientController::class, 'store'])->name('recipients.store');
    Route::patch('/recipients/{recipient}', [RecipientController::class, 'update'])->name('recipients.update');
    Route::delete('/recipients/{recipient}', [RecipientController::class, 'destroy'])->name('recipients.destroy');

    // The shared, claimable view. Rate-limited because it is unauthenticated
    // and the token is the only thing guarding it.
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/l/{token}', [SharedListController::class, 'show'])->name('lists.shared');
        Route::post('/l/{token}/claim/{item}', [SharedListController::class, 'claim'])->name('lists.claim');
        Route::delete('/l/{token}/claim/{item}', [SharedListController::class, 'unclaim'])->name('lists.unclaim');
    });

    /*
    |----------------------------------------------------------------------
    | Alerts and the inbox
    |----------------------------------------------------------------------
    |
    | Signed-in only, unlike lists: an alert fires days later and has to reach
    | someone. A cookie identity has no delivery address, and the cookie may
    | well be gone by the time the price moves.
    */
    Route::middleware('auth')->group(function () {
        Route::post('/alerts', [AlertController::class, 'store'])->name('alerts.store');
        Route::delete('/alerts/{group}', [AlertController::class, 'destroy'])
            ->whereNumber('group')
            ->name('alerts.destroy');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])
            ->name('notifications.read');
    });

    /*
    |----------------------------------------------------------------------
    | Gift Whisperer
    |----------------------------------------------------------------------
    |
    | The wizard is a GET page so it can be indexed and shared. Results come
    | from a POST: a brief describes a real person, and that does not belong in
    | a URL that lands in a referrer header or a shared browser history.
    |
    | Throttled because scoring touches a few hundred rows — cheap, but not
    | free, and the endpoint is unauthenticated.
    */
    Route::get('/gift', [GiftController::class, 'show'])->name('gift');
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/gift', [GiftController::class, 'suggest'])->name('gift.suggest');
        Route::post('/gift/swap', [GiftController::class, 'swap'])->name('gift.swap');
    });

    /*
    |----------------------------------------------------------------------
    | Serendipity
    |----------------------------------------------------------------------
    |
    | "Show me something I didn't know existed." Reads the surprise scores the
    | scoring job wrote; nothing is computed per request.
    */
    Route::get('/surprise', SerendipityController::class)
        ->middleware('throttle:120,1')
        ->name('surprise');

    // Phase 5-6 routes land here:
    //   /{market}/scan                       mobile barcode scanner
    //   /{market}/daily                      today's picks
    //   /{market}/guides                     buying guides
});
