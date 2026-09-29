<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AskController;
use App\Http\Controllers\AskPeopleSettingsController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\ClaimIntentController;
use App\Http\Controllers\ClickBeaconController;
use App\Http\Controllers\ClickOutController;
use App\Http\Controllers\CommunityCoveController;
use App\Http\Controllers\ContributeBarController;
use App\Http\Controllers\ContributeController;
use App\Http\Controllers\CookieConsentController;
use App\Http\Controllers\CovesController;
use App\Http\Controllers\CoveSubscriptionController;
use App\Http\Controllers\CsrfTokenController;
use App\Http\Controllers\DailyCoveController;
use App\Http\Controllers\DiscoverCoveController;
use App\Http\Controllers\Ebay\AccountDeletionController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\GiftController;
use App\Http\Controllers\GiftCoveController;
use App\Http\Controllers\GiftCoveManualController;
use App\Http\Controllers\GiftFeedbackController;
use App\Http\Controllers\GiftIdeasController;
use App\Http\Controllers\GiftLandingController;
use App\Http\Controllers\GiftPledgeController;
use App\Http\Controllers\GiftProfileCardController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\HandoverController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImageProxyController;
use App\Http\Controllers\InviteAcceptController;
use App\Http\Controllers\InviteNotWantedController;
use App\Http\Controllers\ItemTransferController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\ListHelpController;
use App\Http\Controllers\ListItemVoteController;
use App\Http\Controllers\ListMessageController;
use App\Http\Controllers\ListPublishController;
use App\Http\Controllers\ListQuizController;
use App\Http\Controllers\MarketPreferenceController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MyTasteController;
use App\Http\Controllers\NotFoundController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OgImageController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PickReactionController;
use App\Http\Controllers\PopularSearchesController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RecipientController;
use App\Http\Controllers\RecipientProfileController;
use App\Http\Controllers\ReminderEmailController;
use App\Http\Controllers\SavedCoveController;
use App\Http\Controllers\SaveIntentController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SearchAlertController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SearchHelpController;
use App\Http\Controllers\SecretSantaController;
use App\Http\Controllers\SerendipityController;
use App\Http\Controllers\SharedListController;
use App\Http\Controllers\ShopsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SuggestionController;
use App\Http\Controllers\SwipeController;
use App\Http\Controllers\TasteController;
use App\Http\Controllers\TasteTogetherController;
use App\Http\Controllers\WishlistCollaboratorController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\WishlistItemController;
use App\Http\Middleware\CacheAnonymousPage;
use App\Http\StatelessRoutes;
use App\Support\CurrentMarket;
use App\Support\MarketPreference;
use App\Support\SearchUrl;
use App\Support\ShareCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Unprefixed
|--------------------------------------------------------------------------
*/

// Deployment health. Reports the commit that built the image and the last
// migration applied — Coolify's healthcheck target, and the first thing to
// check after a deploy. Stateless (no session, no cookies), like the pictures,
// robots.txt, the sitemaps and the social cards: see App\Http\StatelessRoutes.
Route::get('/health', HealthController::class)
    ->withoutMiddleware(StatelessRoutes::SKIPPED)
    ->name('health');

/*
 * eBay Marketplace Account Deletion — eBay's compliance webhook.
 *
 * Unprefixed and unauthenticated, because eBay calls it and knows nothing about
 * markets or sessions. GET answers the one-off challenge; POST acknowledges a
 * real deletion notification.
 *
 * This is not a nicety: an application with no such endpoint is marked "non
 * compliant" in eBay's developer portal, and a non-compliant keyset does not
 * mint production tokens. It is what makes the eBay connector work at all.
 *
 * Deliberately NOT rate limited. eBay retries an endpoint that does not answer
 * 2xx and counts the failures against compliance, so throttling its retries is
 * a way to fail the requirement while looking defensive. The POST changes no
 * state and logs no personal data, so there is nothing here worth protecting
 * with a limit. See docs/features/ebay-account-deletion.md.
 */
Route::get('/webhooks/ebay/account-deletion', [AccountDeletionController::class, 'challenge'])
    ->name('ebay.deletion.challenge');
Route::post('/webhooks/ebay/account-deletion', [AccountDeletionController::class, 'notify'])
    ->name('ebay.deletion.notify');

/*
 * Pictures we stored ourselves: photos people uploaded to items they typed, and
 * pictures copied from a pasted shop page (see App\Services\Images\ImageStore).
 *
 * Unprefixed, because a picture belongs to no market. The name is a random
 * UUID and the file is never rewritten, so it may be cached for a year.
 */
Route::get('/media/items/{file}', MediaController::class)
    ->where('file', '[0-9a-f-]{36}\.webp')
    ->withoutMiddleware(StatelessRoutes::SKIPPED)
    ->name('media');

/*
 * A merchant's product picture, resized to WebP and kept (docs/features/image-proxy.md).
 *
 * Stateless like /media above: a page draws dozens of these, and each one
 * starting a session would be dozens of Redis writes for a picture. The source
 * is signed, so the route cannot be used to fetch anything our pages did not
 * hand out; making a new copy is rate limited inside the controller, serving a
 * stored one is not.
 */
Route::get('/img/{width}/{signature}/{source}', ImageProxyController::class)
    ->where(['width' => '[0-9]{2,4}', 'signature' => '[0-9a-f]{32}', 'source' => '[A-Za-z0-9_-]{8,2800}'])
    ->withoutMiddleware(StatelessRoutes::SKIPPED)
    ->name('image-proxy');

// Sitemaps and robots. Unprefixed: crawlers look for them at the root, and a
// per-market copy would just be five competing files.
Route::withoutMiddleware(StatelessRoutes::SKIPPED)->group(function (): void {
    Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');
    Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
    Route::get('/sitemap/{market}/{page}.xml', [SitemapController::class, 'market'])
        ->whereNumber('page')
        ->name('sitemap.market');
});

// Root sends visitors to the market they chose, or failing that to our best
// guess from Accept-Language. A 302, never a 301: this destination varies per
// visitor and must not be cached into permanence.
Route::get('/', function (Request $request) {
    $market = MarketPreference::resolve($request);

    // Per-visitor, so it must not land in a shared cache — the response varies
    // on a cookie and on a request header, and a CDN that kept one copy would
    // hand the next visitor somebody else's market. `private` alone would let a
    // browser reuse a stale guess after the visitor switched, so: no-store.
    return redirect('/'.$market->value, 302)
        ->header('Cache-Control', 'no-store, private');
})->name('root');

// A CSRF token for a page served from the anonymous page cache, which carries
// none. Asked by the browser right before its first write; it starts the
// session. See App\Http\Controllers\CsrfTokenController.
Route::get('/csrf', CsrfTokenController::class)
    ->middleware('throttle:60,1')
    ->name('csrf');

// Where the switcher posts a choice. Unprefixed and POST-only; the reasoning is
// in MarketPreferenceController.
Route::post('/market', MarketPreferenceController::class)->name('market.choose');

// Where the cookie banner posts its answer. Unprefixed for the same reason the
// switcher is — consent is about the visitor, not the catalogue they are in.
Route::post('/consent', CookieConsentController::class)->name('consent.choose');

// Closing the contribute bar under the header. Unprefixed and POST-only, like
// the two above: it is about this visitor, and only the bar's own close
// button may write it. See App\Support\ContributeBar.
Route::post('/contribute-bar', ContributeBarController::class)->name('contribute-bar.close');

/*
 * Where Google sends the visitor back. Unprefixed, and it has to be.
 *
 * Google matches a redirect URI by exact string, so a market-scoped callback
 * would mean registering one URI per market per environment — five markets
 * times three environments, kept in sync with the Market enum by hand forever.
 * One URI is registered instead, and the market survives the round-trip in the
 * session, stashed by GoogleController::redirect() before the visitor leaves.
 *
 * The outbound leg stays market-scoped at /{market}/auth/google: it is a link
 * we generate, not a URI Google has to recognise, and it needs the market in
 * hand to stash it.
 */
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])
    ->middleware('guest')
    ->name('login.google.callback');

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

/*
|--------------------------------------------------------------------------
| A share token, as it appears in a URL
|--------------------------------------------------------------------------
|
| `wishlists.share_token`, `recipients.share_token` and
| `list_quizzes.share_token` are all **uuid columns**, and an unconstrained
| `{token}` segment reaches Postgres as `where share_token = 'suggest'`. That
| does not return zero rows — it raises `22P02: invalid input syntax for type
| uuid` and the visitor gets a 500 where they should have had a 404.
|
| Every stray path under those prefixes did it: a mistyped link, a crawler
| walking a URL it half-remembered, a client that built an address with an empty
| token and left the `//` in it — `/for//suggest` collapses to `/for/suggest`,
| which is how this was found.
|
| The pattern is written out at each of the three groups rather than hoisted
| into a constant. A file-level `const` here is defined again every time the
| route file is re-evaluated, which a test suite does once per test: the first
| test passes and the other thirty-eight die on "Constant UUID_TOKEN already
| defined". Three literals beat that.
|
| And deliberately not `Route::pattern('token', …)`: two other routes take a
| `{token}` that is **not** a uuid — the Cove confirm and unsubscribe links are
| 64 hex characters — and a global pattern that happens to be overridden in both
| places today is a trap for the third one.
*/

Route::prefix('{market}')->group(function () {
    Route::get('/', HomeController::class)
        ->middleware(CacheAnonymousPage::ALIAS)->name('home');

    /*
     * Throttled, for the reason `/list-search` gives below: the live half of a
     * search costs real requests to bol and Amazon, and the Amazon one cannot
     * be cached. This route had no ceiling at all while the signed-in one did,
     * so an anonymous loop over `?q=<random>` could spend the PA-API quota and
     * write a `search_log` row per term. Sixty a minute is far more than a
     * person types and far less than a script does.
     */
    Route::get('/search', SearchController::class)
        ->middleware('throttle:60,1')
        ->name('search');

    /*
     * The same page with the term in the path, in the market's own word:
     * /be-nl/zoek/draadloze-koptelefoon, /be-fr/recherche/casque. Every
     * market's segment is accepted on every market (the canonical names the
     * right one), and the term is constrained to what SearchUrl can turn back
     * into words without guessing. See App\Support\SearchUrl.
     */
    Route::get('/{segment}/{term}', SearchController::class)
        ->where(['segment' => implode('|', SearchUrl::segments()), 'term' => '[a-z0-9]+(?:-[a-z0-9]+)*'])
        ->middleware('throttle:60,1')
        ->name('search.term');

    // What the box accepts and how the camera does it. Next to /search rather
    // than with about/privacy/terms: it is documentation of a tool, not a
    // document about the company, and the only pages that link to it are the
    // ones with a search field on them.
    Route::get('/search-help', SearchHelpController::class)
        ->middleware(CacheAnonymousPage::ALIAS)->name('search-help');

    /*
     * What people search for here, as one page.
     *
     * The internal-linking hub that replaced the related-search chips under
     * every result set — one cached aggregate instead of a trigram scan per
     * page. Next to /search-help for the same reason that is here: it is about
     * the search box rather than about the company.
     */
    Route::get('/popular-searches', PopularSearchesController::class)
        ->middleware(CacheAnonymousPage::ALIAS)->name('popular-searches');

    /*
     * Tell us what is wrong.
     *
     * Signed-out on purpose — see FeedbackController. The POST is rate limited
     * in the controller rather than by a `throttle` middleware, because a
     * throttled request must answer like a successful one rather than with a
     * 429 that tells a script exactly where the line is.
     */
    /*
     * How the site works, and where to say it does not, on one page.
     *
     * The how-to pages were reachable only from the screen each explains, so a
     * visitor who had already given up on that screen had nowhere to go. This
     * gathers them and puts the report form under them.
     */
    Route::get('/help', HelpController::class)
        ->middleware(CacheAnonymousPage::ALIAS)->name('help');

    /*
     * Kept as a redirect, not a page.
     *
     * The form lives on `/help` now, with the how-to pages above it. This
     * address has been in the menu and the footer for months and is in people's
     * history, so it goes on answering - 301, because the move is permanent and
     * a 302 would leave crawlers holding the old one.
     *
     * The POST below stays exactly where it is: it is where the form submits
     * from either page, and moving it would be a change to a working endpoint
     * for the sake of tidiness.
     */
    Route::get('/feedback', fn (CurrentMarket $current) => redirect($current->url('help'), 301))
        ->name('feedback');
    Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');

    /*
     * "Denk mee": feedback, the ideas we are weighing, voting on them and
     * suggesting one (docs/features/contribute.md). The page is open to
     * guests; voting and suggesting need an account. `/contribute` in every
     * language, like `/people`: the heading is translated, the path is not.
     */
    Route::get('/contribute', [ContributeController::class, 'index'])->name('contribute');

    Route::middleware('auth')->group(function () {
        Route::post('/contribute/ideas/{idea}/vote', [ContributeController::class, 'vote'])
            ->whereNumber('idea')
            ->middleware('throttle:60,1')
            ->name('contribute.vote');
        Route::delete('/contribute/ideas/{idea}/vote', [ContributeController::class, 'unvote'])
            ->whereNumber('idea')
            ->middleware('throttle:60,1')
            ->name('contribute.unvote');
        // A burst limit; the daily one is FeatureSuggestions::PER_DAY.
        Route::post('/contribute/suggestions', [ContributeController::class, 'suggest'])
            ->middleware('throttle:10,1')
            ->name('contribute.suggest');
    });

    // The slug is decoration; the id is identity. A stale slug redirects rather
    // than 404s, so old shared links keep working after a retitle.
    Route::get('/p/{group}/{slug?}', ProductController::class)
        ->middleware(CacheAnonymousPage::ALIAS)
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

        // Opening the link shows a page with a button; only the button signs
        // in (2026-09-28). Mail scanners open links, they do not press buttons.
        // See MagicLinkController::confirm().
        Route::get('/auth/magic/{token}', [MagicLinkController::class, 'confirm'])
            ->middleware('throttle:20,1')
            ->name('login.magic');

        Route::post('/auth/magic/{token}', [MagicLinkController::class, 'consume'])
            ->middleware('throttle:20,1')
            ->name('login.magic.consume');

        // Outbound only. The callback is registered unprefixed, above.
        Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('login.google');
    });

    Route::post('/logout', [MagicLinkController::class, 'logout'])
        ->middleware('auth')
        ->name('logout');

    /*
    |----------------------------------------------------------------------
    | Wishlists
    |----------------------------------------------------------------------
    |
    | Keeping a list requires an account. A list belonging to a cookie cannot
    | be reached from a second device, does not survive clearing the browser,
    | and has no address a reminder could ever be sent to — so it looks like a
    | feature and behaves like a draft.
    |
    | Reading stays open. Claiming no longer does — a claim is hashed from the
    | claimer's identity, and an anonymous identity is a cookie, so an anonymous
    | claim could not be seen from a second device, could not be released from
    | one, and vanished with the browser's storage while the item stayed spoken
    | for. The press is not lost to the sign-in: `/claim-intent` stashes it and
    | `ReplayPendingClaim` finishes it. See App\Services\Wishlist\PendingClaim.
    */
    /*
     * How lists work, with pictures.
     *
     * `/lists-help` rather than `/lists/help`, because `/lists/{list}` is right
     * underneath and a list whose id happened to be "help" would shadow it.
     * It mirrors `/search-help`, which sits beside `/search` for the same
     * reason: it documents a tool rather than the company.
     */
    Route::get('/lists-help', [ListHelpController::class, 'index'])
        ->middleware(CacheAnonymousPage::ALIAS)->name('lists-help');
    // One page per capability. The allowlist is the controller's; the
    // pattern keeps anything that is not a plain word out of the log.
    Route::get('/lists-help/{topic}', [ListHelpController::class, 'topic'])
        ->middleware(CacheAnonymousPage::ALIAS)
        ->where('topic', '[a-z]+')
        ->name('lists-help.topic');

    Route::get('/lists', [WishlistController::class, 'index'])->name('lists');
    Route::get('/lists/{list}', [WishlistController::class, 'show'])->name('lists.show');

    /*
     * "My people": saved people and friends on one list (2026-09-26, see
     * docs/features/my-people.md). Outside `auth` like `/lists`, so a guest
     * gets what the page is for and a sign-in rather than a bare login form;
     * nothing about anybody is sent to them.
     *
     * `/friends` was the friends' own page and now redirects here: emails,
     * the help pages and bookmarks carry it. Outside `auth` too, so a guest
     * following an old link lands on the explanation, not the login form.
     * The friend actions (`POST /friends` and the rest) stay where they were.
     */
    Route::get('/people', [PeopleController::class, 'index'])->name('people');
    Route::get('/friends', [FriendController::class, 'index'])->name('friends');

    // Where a save could go. JSON, fetched by the save picker on first open.
    Route::get('/list-options', [WishlistItemController::class, 'options'])->name('items.options');

    // Which products are already saved, so a card can show it without asking.
    Route::get('/saved-items', [WishlistItemController::class, 'saved'])->name('items.saved');

    /*
     * A save pressed before there was an account to keep it in.
     *
     * Unauthenticated by definition — it exists for people who have not signed
     * up — and it writes only to the caller's own session. Throttled anyway,
     * because an unauthenticated POST with no rate limit is a habit worth not
     * forming. See App\Services\Wishlist\PendingSave.
     */
    Route::post('/save-intent', [SaveIntentController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('items.intent');

    /*
     * A claim pressed before there was an account to hang it on.
     *
     * The sibling of `/save-intent` above, and unauthenticated for the same
     * reason: it exists for people who have not signed up. It writes only to
     * the caller's own session — nothing is claimed here — and it deliberately
     * does not check that the token names a real list, because answering that
     * would make it an oracle for guessing share tokens.
     */
    Route::post('/claim-intent', [ClaimIntentController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('lists.claim.intent');

    Route::middleware('auth')->group(function () {
        /*
         * Saved Coves: a bookmark on a published Cove, and "Make it my list",
         * a copy into a list of your own. See SavedCoveController and
         * docs/features/saved-coves.md.
         */
        Route::post('/coves/{set}/save', [SavedCoveController::class, 'store'])
            ->whereNumber('set')
            ->middleware('throttle:60,1')
            ->name('coves.save');
        Route::delete('/coves/{set}/save', [SavedCoveController::class, 'destroy'])
            ->whereNumber('set')
            ->name('coves.unsave');
        Route::post('/coves/{set}/copy', [SavedCoveController::class, 'copy'])
            ->whereNumber('set')
            ->middleware('throttle:20,1')
            ->name('coves.copy');

        /*
         * Community Coves: the same Save and "Make it my list" on a list
         * somebody published, and the owner's switch that publishes it. See
         * CommunityCoveController, ListPublishController and
         * docs/features/community-coves.md.
         */
        Route::post('/coves/community/{slug}/save', [CommunityCoveController::class, 'save'])
            ->where('slug', '[a-z0-9-]+')
            ->middleware('throttle:60,1')
            ->name('community.save');
        Route::delete('/coves/community/{slug}/save', [CommunityCoveController::class, 'unsave'])
            ->where('slug', '[a-z0-9-]+')
            ->name('community.unsave');
        Route::post('/coves/community/{slug}/copy', [CommunityCoveController::class, 'copy'])
            ->where('slug', '[a-z0-9-]+')
            ->middleware('throttle:20,1')
            ->name('community.copy');
        Route::post('/lists/{list}/publish', [ListPublishController::class, 'store'])
            ->whereUuid('list')
            ->middleware('throttle:20,1')
            ->name('lists.publish');
        Route::delete('/lists/{list}/publish', [ListPublishController::class, 'destroy'])
            ->whereUuid('list')
            ->name('lists.unpublish');

        /*
         * Adding somebody by their address.
         *
         * Throttled harder than the page it sits on, because the interesting
         * abuse is volume: the endpoint answers identically whether or not an
         * address has an account here — see App\Services\Social\FriendInvites
         * — and the rate limit is the second half of that defence. A caller
         * cannot tell one address from another, and cannot walk a list of them.
         */
        Route::post('/friends', [FriendController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('friends.store');

        // What your friends see of you. Not a permission — see the controller.
        Route::patch('/friends/settings', [FriendController::class, 'settings'])->name('friends.settings');

        // "Mijn smaak": your own gift taste, which friends' searches start from (docs/features/my-taste.md).
        Route::get('/my-taste', [MyTasteController::class, 'show'])->name('my-taste');
        Route::put('/my-taste', [MyTasteController::class, 'update'])->name('my-taste.update');
        Route::post('/my-taste/learn', [MyTasteController::class, 'learn'])
            ->middleware('throttle:20,1')
            ->name('my-taste.learn');

        // The birthday you wrote down about somebody, on your side only.
        Route::patch('/friends/{friend}', [FriendController::class, 'note'])
            ->whereNumber('friend')
            ->name('friends.note');

        Route::delete('/friends/{friend}', [FriendController::class, 'destroy'])
            ->whereNumber('friend')
            ->name('friends.destroy');

        Route::post('/lists', [WishlistController::class, 'store'])->name('lists.store');
        Route::patch('/lists/{list}', [WishlistController::class, 'update'])->name('lists.update');

        /*
         * "Share with friends": pick names, they get an email and the list on
         * their friends page.
         *
         * Throttled because it sends mail on somebody else's behalf, which is
         * the half of this that could be abused at volume — the recipients are
         * the caller's own friends, so the ceiling is low on purpose and a
         * person sharing one list with everybody they know stays well under it.
         */
        Route::post('/lists/{list}/share-with-friends', [WishlistController::class, 'shareWithFriends'])
            ->middleware('throttle:20,1')
            ->name('lists.share-with-friends');

        Route::delete('/lists/{list}/share-with-friends/{friend}', [WishlistController::class, 'unshareFromFriend'])
            ->whereNumber('friend')
            ->name('lists.unshare-from-friend');
        Route::delete('/lists/{list}', [WishlistController::class, 'destroy'])->name('lists.destroy');

        /*
         * Throttled since 2026-09-26, when a saved link started queueing a
         * lookup that may call a connector or read a shop's page. Sixty a
         * minute is far above anybody filling a list by hand.
         */
        Route::post('/list-items', [WishlistItemController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('items.store');
        Route::patch('/list-items/{item}', [WishlistItemController::class, 'update'])
            ->middleware('throttle:60,1')
            ->name('items.update');

        // A photo of your own on something you typed. See ImageStore.
        Route::post('/list-items/{item}/photo', [WishlistItemController::class, 'photo'])
            ->middleware('throttle:20,1')
            ->name('items.photo');
        Route::delete('/list-items/{item}/photo', [WishlistItemController::class, 'removePhoto'])
            ->name('items.photo.remove');
        Route::delete('/list-items/{item}', [WishlistItemController::class, 'destroy'])->name('items.destroy');

        /*
         * Searching from inside a list, without leaving it.
         *
         * Throttled because the live half of a search costs real requests to
         * bol and Amazon, and this one is reached by typing. `SearchService`
         * caches the mirrorable connectors; the throttle is what bounds the
         * one it cannot.
         */
        Route::get('/list-search', [WishlistItemController::class, 'find'])
            ->middleware('throttle:60,1')
            ->name('items.find');

        /*
         * Filling one list, rather than saving one product.
         *
         * `add` turns the mode on and lands on search; `done` turns it off and
         * goes back to the list. Both are GETs because both are reached from a
         * link on a page, and neither changes anything a visitor could not undo
         * by pressing the other one. See App\Services\Wishlist\AddingMode.
         */
        Route::get('/lists/{list}/add', [WishlistController::class, 'add'])->name('lists.add');
        Route::get('/done-adding', [WishlistController::class, 'doneAdding'])->name('lists.done_adding');

        /*
         * The people a list is about, and handing a list to one of them.
         *
         * Behind `auth` since 2026-09-06. They sat outside it with only an
         * `Owner::exists()` check, which every request passes — the identity
         * middleware manufactures an anonymous owner for anyone without a
         * cookie — so an unauthenticated caller could create recipients
         * without limit. Keeping a list needs an account now, so nothing a
         * visitor can reach creates one of these without being signed in.
         */
        Route::post('/recipients', [RecipientController::class, 'store'])->name('recipients.store');
        Route::patch('/recipients/{recipient}', [RecipientController::class, 'update'])->name('recipients.update');
        Route::delete('/recipients/{recipient}', [RecipientController::class, 'destroy'])->name('recipients.destroy');

        // "Help me find out what :name likes": This or that played by several
        // people about one of yours (docs/features/taste-together.md).
        Route::middleware('throttle:20,1')->whereUuid('recipient')->group(function () {
            Route::post('/recipients/{recipient}/taste-together', [TasteTogetherController::class, 'store'])->name('recipients.together.store');
            Route::delete('/recipients/{recipient}/taste-together', [TasteTogetherController::class, 'destroy'])->name('recipients.together.destroy');
            Route::post('/recipients/{recipient}/taste-together/apply', [TasteTogetherController::class, 'apply'])->name('recipients.together.apply');
        });
        /*
         * A saved person's page: gift history, "I gave this", and the next
         * step after what they were given. The owner's only; see
         * PersonController and docs/features/gift-history.md.
         */
        Route::get('/people/{recipient}', [PersonController::class, 'show'])
            ->whereUuid('recipient')
            ->name('people.show');
        Route::post('/people/{recipient}/gifts', [PersonController::class, 'store'])
            ->whereUuid('recipient')
            ->middleware('throttle:30,1')
            ->name('people.gifts.store');
        Route::delete('/people/{recipient}/gifts/{gift}', [PersonController::class, 'destroy'])
            ->whereUuid('recipient')
            ->whereNumber('gift')
            ->name('people.gifts.destroy');
        // "Deel een lijst en laat anderen iets voorstellen", from Find a gift:
        // the list for this person, made if there is none, opened on Share.
        // A POST because it may create a list; a link must never do that.
        Route::post('/people/{recipient}/share-list', [PersonController::class, 'shareList'])
            ->whereUuid('recipient')
            ->middleware('throttle:30,1')
            ->name('people.share-list');
        // The search card on Find a gift: the list for this person as JSON,
        // made on the first press, so the add panel can put things on it.
        Route::post('/people/{recipient}/list', [PersonController::class, 'listFor'])
            ->whereUuid('recipient')
            ->middleware('throttle:30,1')
            ->name('people.list');
        // The same for a relationship ("Collega") chosen instead of a saved person.
        Route::post('/people/for-relationship/list', [PersonController::class, 'listForRelationship'])
            ->middleware('throttle:30,1')
            ->name('people.relationship-list');

        // Hand a list to the person it was built for. It stops being research
        // and becomes theirs — which is what makes it claimable.
        Route::post('/lists/{list}/handover', [HandoverController::class, 'store'])->name('lists.handover');
    });

    /*
     * Revoking access that was granted by name, before sharing became a link.
     *
     * Nothing creates collaborators any more — `ListOpen` and the share link
     * replaced them — but people granted access that way still have it, so the
     * owner keeps a way to take it back. Only the owner: a collaborator who
     * could remove collaborators is a list whose audience changes under its
     * owner.
     */
    Route::delete('/lists/{list}/collaborators/{collaborator}', [WishlistCollaboratorController::class, 'destroy'])
        ->middleware('auth')
        ->name('lists.collaborators.destroy');

    /*
    |----------------------------------------------------------------------
    | Copying an item onto another list
    |----------------------------------------------------------------------
    |
    | One verb and two sources: a row on a list of mine, reached by that list's
    | id, and a row on the recipient's own list, reached by their share token —
    | which is how the Ask panel reads it in the first place.
    |
    | Copy only, never move. Removal already exists on every row, so a move
    | would be a second way to do what the page can already do in two presses,
    | with a failure mode the copy does not have. Neither endpoint touches the
    | source list.
    */
    Route::post('/lists/{list}/items/{item}/copy', [ItemTransferController::class, 'between'])
        ->whereNumber('item')
        ->name('items.copy');

    // A wishlist share token, so the same pattern as the shared-view group
    // below. It is a ten-character code now, not a uuid — App\Support\ShareCode.
    Route::post('/l/{token}/items/{item}/copy', [ItemTransferController::class, 'fromShared'])
        ->where('token', ShareCode::pattern())
        ->whereNumber('item')
        ->name('items.copy.shared');

    // Suggestions the owner has not decided on yet.
    Route::post('/suggestions/{item}/accept', [SuggestionController::class, 'accept'])->name('suggestions.accept');
    Route::delete('/suggestions/{item}', [SuggestionController::class, 'destroy'])->name('suggestions.destroy');

    /*
     * The shared, claimable view. Rate-limited because it is unauthenticated
     * and the token is the only thing guarding it.
     *
     * `wishlists.share_token` is a ten-character code now rather than a uuid,
     * so the constraint is a courtesy rather than a load-bearing guard: it was
     * a native `uuid` column, where a stray `/l/suggest` raised `22P02` and
     * 500'd. On text it is simply zero rows. The pattern lives in `ShareCode`
     * so the format is stated once — and it stays generous, so an old link
     * reaches the controller and gets an honest 404 rather than a route miss.
     */
    Route::middleware('throttle:60,1')->where(['token' => ShareCode::pattern()])->group(function () {
        Route::get('/l/{token}', [SharedListController::class, 'show'])->name('lists.shared');
        Route::post('/l/{token}/claim/{item}', [SharedListController::class, 'claim'])->name('lists.claim');
        Route::delete('/l/{token}/claim/{item}', [SharedListController::class, 'unclaim'])->name('lists.unclaim');
        Route::post('/l/{token}/sent/{item}', [SharedListController::class, 'markSent'])->name('lists.sent');

        /*
         * A Secret Friend invite, by its code alone: `/s/{code}`, a sibling
         * of `/l/{code}` (2026-09-13). The link was `/santa/{uuid}/join/{uuid}`,
         * ninety characters pasted into group chats by hand; the code is the
         * whole credential and is unique, so the group id said nothing it did
         * not. The long form stays below for links already sent.
         */
        Route::get('/s/{token}', [SecretSantaController::class, 'inviteByCode'])->name('santa.invite.short');
        Route::post('/s/{token}', [SecretSantaController::class, 'joinByCode'])->name('santa.join.short');

        /*
         * Which present the group should buy.
         *
         * Mounted on the share token beside the pledge routes, because that is
         * how a member reaches a group list at all — most of them have no
         * account, which is the point of the join-by-link design.
         */
        Route::post('/l/{token}/vote/{item}', [ListItemVoteController::class, 'store'])->name('lists.vote');
        Route::delete('/l/{token}/vote/{item}', [ListItemVoteController::class, 'destroy'])->name('lists.vote.destroy');

        /*
         * The pot, which names no item.
         *
         * There were two shapes here for a day — `/pledge/{item}` as well, for
         * a wish list where several people might go in on one expensive thing.
         * Rendered, that put an "I'm in" under every card of a six-item list,
         * beside the claim button that is the real action there. Chipping in is
         * a fact about the present, and a group list is what a shared present
         * is.
         */
        Route::post('/l/{token}/pledge', [GiftPledgeController::class, 'store'])->name('lists.pledge');
        Route::delete('/l/{token}/pledge', [GiftPledgeController::class, 'destroy'])->name('lists.pledge.destroy');
        Route::post('/l/{token}/suggest', [SuggestionController::class, 'store'])->name('lists.suggest');

        /*
        |------------------------------------------------------------------
        | The board beside a shared list
        |------------------------------------------------------------------
        |
        | The conversation that decides the buying, on the page that knows what
        | has been claimed and what the pot stands at — rather than in the group
        | chat the link was pasted into, which knows none of it.
        |
        | Addressed by the list's share token like everything else here, because
        | that token IS the permission: whoever holds it may read the list, and
        | reading the board is the same right. Who may actually see it is
        | `App\Services\Wishlist\Board`, which hangs the question off the claim
        | gate — a board is claim state in prose.
        |
        | Throttled harder than a claim. A claim is one press per item and the
        | table constrains it; a message is free text with nothing stopping a
        | hundred of them.
        */
        Route::post('/l/{token}/messages', [ListMessageController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('lists.board.store');

        Route::delete('/l/{token}/messages/{message}', [ListMessageController::class, 'destroy'])
            ->whereNumber('message')
            ->name('lists.board.destroy');
    });

    /*
    |----------------------------------------------------------------------
    | "Tell them what you'd actually like"
    |----------------------------------------------------------------------
    |
    | The other end of a recipient. The token is a capability, exactly as with
    | /l/{token} — it grants describing yourself and curating your own list, and
    | nothing else. Same rate limit for the same reason: it is unauthenticated
    | and the token is the only thing guarding it.
    */
    // Uuid-constrained, like `/l/{token}` and for the same reason. `/for/{token}`
    // is where the 500 was actually reported: a request for `/for/suggest`
    // matched here and asked Postgres for a uuid equal to "suggest".
    Route::middleware('throttle:60,1')->where(['token' => '[0-9a-fA-F-]{36}'])->group(function () {
        Route::get('/for/{token}', [RecipientProfileController::class, 'show'])->name('recipients.self');
        Route::post('/for/{token}', [RecipientProfileController::class, 'update'])->name('recipients.self.update');
        Route::post('/for/{token}/claim', [RecipientProfileController::class, 'claim'])->name('recipients.self.claim');
        // Tighter than the group: every request runs the suggestion engine,
        // for anybody holding the link. Its own counter (the third argument),
        // since a bare `throttle` shares one per visitor with every other
        // throttled route and would count this request twice.
        Route::get('/for/{token}/suggest', [RecipientProfileController::class, 'suggest'])
            ->middleware('throttle:30,1,for-suggest')
            ->name('recipients.self.suggest');
        Route::post('/for/{token}/list', [RecipientProfileController::class, 'startList'])
            ->name('recipients.self.list');
        // This or that, chosen by the person themselves (TasteController).
        Route::get('/for/{token}/taste', [TasteController::class, 'selfShow'])->name('recipients.self.taste');
        Route::post('/for/{token}/taste', [TasteController::class, 'selfResult'])->name('recipients.self.taste.result');
        Route::post('/for/{token}/taste/save', [TasteController::class, 'selfSave'])->name('recipients.self.taste.save');
    });

    /*
     * This or that together, for whoever holds the link: play about one of
     * somebody's people, no account needed. The token is the whole permission,
     * as with `/l/{token}`, so the same limit; finishing writes a row, so
     * harder there. See docs/features/taste-together.md.
     */
    Route::middleware('throttle:60,1')->where(['token' => ShareCode::pattern()])->group(function () {
        Route::get('/t/{token}', [TasteTogetherController::class, 'play'])->name('taste.together');
        Route::post('/t/{token}', [TasteTogetherController::class, 'finish'])
            ->middleware('throttle:10,1')
            ->name('taste.together.finish');
    });

    /*
    |----------------------------------------------------------------------
    | "How well do you know them?"
    |----------------------------------------------------------------------
    |
    | A quiz over somebody's list. Playable signed-out, because asking for a
    | signup before the first guess loses the player — and the share artefact is
    | a score, which is worthless if nobody ever gets one.
    */
    // Same constraint, same reason: `list_quizzes.share_token` is a uuid too.
    Route::middleware('throttle:60,1')->where(['token' => '[0-9a-fA-F-]{36}'])->group(function () {
        Route::post('/lists/{list}/quiz', [ListQuizController::class, 'store'])->name('quiz.store');
        Route::get('/q/{token}', [ListQuizController::class, 'show'])->name('quiz.show');
        Route::post('/q/{token}', [ListQuizController::class, 'submit'])->name('quiz.submit');
    });

    /*
    |----------------------------------------------------------------------
    | Secret Santa
    |----------------------------------------------------------------------
    |
    | An assignment layer over ordinary lists. Creating a group needs an
    | account (somebody has to own it); joining and reading your own assignment
    | do not, because requiring a login to be in an office Secret Santa is how
    | most of the office does not join.
    |
    | Throttled: every member-facing route is guarded by a token alone.
    */
    Route::middleware('auth')->group(function () {
        Route::post('/santa', [SecretSantaController::class, 'store'])->name('santa.store');
        Route::post('/santa/{group}/draw', [SecretSantaController::class, 'draw'])->name('santa.draw');
        Route::delete('/santa/{group}', [SecretSantaController::class, 'destroy'])->name('santa.destroy');

        /*
         * Repairing a draw that has already happened.
         *
         * Organiser-only, and both change as little as possible: removing
         * somebody hands their giftee to their giver, and a redraw swaps two
         * givers. Every member is holding an email naming one person and an
         * email cannot be unsent, so the alternative — drawing again — would
         * silently invalidate all of them. See app/Services/Gift/SantaRepair.php.
         */
        Route::delete('/santa/{group}/members/{member}', [SecretSantaController::class, 'removeMember'])
            ->whereNumber('member')
            ->name('santa.member.destroy');

        Route::post('/santa/{group}/members/{member}/redraw', [SecretSantaController::class, 'redraw'])
            ->whereNumber('member')
            ->name('santa.redraw');
    });

    Route::middleware('throttle:60,1')->group(function () {
        // The hub and the create form. Public so the page can explain itself
        // before asking for an account.
        Route::get('/santa', [SecretSantaController::class, 'index'])->name('santa');
        Route::get('/santa/{group}', [SecretSantaController::class, 'show'])->name('santa.show');
        /*
         * The same URL, both verbs.
         *
         * This was POST only, and it is the URL the organiser shares — so every
         * invite ever sent answered a browser with 405. The GET is the invite
         * page; the POST is the form on it.
         */
        Route::get('/santa/{group}/join/{token}', [SecretSantaController::class, 'invite'])->name('santa.invite');
        Route::post('/santa/{group}/join/{token}', [SecretSantaController::class, 'join'])->name('santa.join');
        Route::get('/santa/{group}/me/{token}', [SecretSantaController::class, 'me'])->name('santa.me');
        Route::post('/santa/{group}/me/{token}/done', [SecretSantaController::class, 'markDone'])->name('santa.done');
        Route::post('/santa/{group}/list', [SecretSantaController::class, 'attachList'])->name('santa.list');
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

        // A watched search: the same machinery pointed at a query rather than
        // a product. See docs/features/search-alerts.md.
        Route::post('/search-alerts', [SearchAlertController::class, 'store'])->name('search-alerts.store');
        Route::delete('/search-alerts/{alert}', [SearchAlertController::class, 'destroy'])
            ->whereNumber('alert')
            ->name('search-alerts.destroy');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])
            ->name('notifications.read');
        // Reminder emails on or off; see ReminderEmailController.
        Route::post('/notifications/reminder-emails', [ReminderEmailController::class, 'update'])
            ->name('reminders.email');
        // Ask others and your people: send mine, show me theirs, by email.
        Route::post('/notifications/ask-people', [AskPeopleSettingsController::class, 'update'])
            ->name('ask.people.settings');
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
    /*
     * The Gift Cove: one page that explains every gifting tool and shows what
     * you already have. These features arrived one at a time and were each
     * reachable from somewhere different — individually findable, collectively
     * invisible.
     *
     * `/gift-cove`, not `/cove`: a Cove is already a buying guide here, and one
     * word meaning two things in the same URL space is a trap.
     */
    Route::get('/gift-cove', GiftCoveController::class)->name('gift-cove');

    /*
     * The manual, on its own page.
     *
     * It was the bottom half of the hub, which has two readers wanting
     * opposite things: one is here to use a tool and one to understand
     * it. A page also gives the explanation an address — a section behind
     * a `#manual` anchor cannot be linked to from an email or a search
     * result.
     */
    Route::get('/gift-cove/how-it-works', GiftCoveManualController::class)
        ->middleware(CacheAnonymousPage::ALIAS)->name('gift-cove.manual');

    /*
     * And the same for the discovery half: one page explaining the Daily Cove,
     * Surprise and the Coves archive, which were three header entries that read
     * as three unrelated links.
     *
     * `/discover-cove`, not `/discover` — that one is the mode dial below, a
     * surface you operate rather than a page that explains. Same reasoning as
     * `/gift-cove` above.
     */
    Route::get('/discover-cove', DiscoverCoveController::class)->name('discover-cove');

    Route::get('/gift', [GiftController::class, 'show'])->name('gift');
    Route::get('/gift/taste', [TasteController::class, 'show'])->name('gift.taste');
    // Swipe gifts: one card at a time, right onto the list (docs/features/swipe-gifts.md).
    Route::get('/gift/swipe', [SwipeController::class, 'show'])->name('gift.swipe');
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/gift', [GiftController::class, 'suggest'])->name('gift.suggest');
        Route::post('/gift/swap', [GiftController::class, 'swap'])->name('gift.swap');
        Route::post('/gift/more', [GiftController::class, 'more'])->name('gift.more');
        // Thumbs up and down on an idea (docs/features/find-a-gift.md).
        Route::post('/gift/feedback', GiftFeedbackController::class)->name('gift.feedback');

        /*
         * This or that: taste discovery by choosing. The page holds the
         * rounds and the choices; these only ever read the catalogue, except
         * `save`, which keeps a result on one of the visitor's own people.
         */
        Route::post('/gift/taste', [TasteController::class, 'result'])->name('gift.taste.result');
        Route::post('/gift/taste/next', [TasteController::class, 'next'])->name('gift.taste.next');
        Route::post('/gift/taste/save', [TasteController::class, 'save'])->name('gift.taste.save');
        Route::post('/gift/swipe/next', [SwipeController::class, 'next'])->name('gift.swipe.next');
    });

    /*
     * "My gift profile": a card made after choosing for yourself, whose link
     * opens Find a gift filled in. Making one writes a row, so it is
     * throttled like a save. See docs/features/gift-profile-card.md.
     */
    Route::post('/gift/card', [GiftProfileCardController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('gift.card.store');
    Route::middleware('throttle:60,1')->where(['token' => ShareCode::pattern()])->group(function () {
        Route::get('/gift/card/{token}', [GiftProfileCardController::class, 'show'])->name('gift.card');
        Route::delete('/gift/card/{token}', [GiftProfileCardController::class, 'destroy'])->name('gift.card.destroy');
    });

    /*
    |----------------------------------------------------------------------
    | Ask others
    |----------------------------------------------------------------------
    |
    | The board where people ask what to buy and other people answer. Reading is
    | open and indexable — a question with good answers on it is exactly the
    | page that should rank, and a login wall is how it never does. Writing
    | needs an account, which is what gives a public post a person behind it.
    |
    | Nothing on this surface publishes anything. A post is created `pending`
    | and `TriageCommunityPost` decides, so the only thing that can put a
    | stranger's writing on a public page is a queued job (invariant #1).
    |
    | Writes are throttled hard. This is the one endpoint where a visitor can
    | create rows that cost money to moderate, and the rate that matters is
    | "how fast can one account fill the queue", not "how fast can they read".
    */
    Route::get('/ask', [AskController::class, 'index'])->name('ask');

    Route::get('/ask/{question}/{slug?}', [AskController::class, 'show'])
        ->whereNumber('question')
        ->name('ask.show');

    /*
     * A question for the asker's people only, by its link code. Never by id:
     * ids can be counted, and the code is the permission (as on a shared
     * list). Throttled like the list links, so the code cannot be guessed at
     * speed.
     */
    Route::get('/ask/p/{token}', [AskController::class, 'showPeople'])
        ->where('token', '[0-9a-z]{6,32}')
        ->middleware('throttle:60,1')
        ->name('ask.people.show');

    Route::middleware(['auth', 'throttle:10,1'])->group(function () {
        Route::post('/ask', [AskController::class, 'store'])->name('ask.store');
        Route::post('/ask/{question}/answers', [AskController::class, 'answer'])
            ->whereNumber('question')
            ->name('ask.answer');
        Route::post('/ask/p/{token}/answers', [AskController::class, 'answerPeople'])
            ->where('token', '[0-9a-z]{6,32}')
            ->name('ask.people.answer');
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

    /*
    |----------------------------------------------------------------------
    | The Daily Cove
    |----------------------------------------------------------------------
    |
    | One page a day: a themed set of finds and an article. Every edition keeps a
    | permanent URL — the archive is the SEO asset, and a daily column whose past
    | editions 404 has no archive to link to.
    |
    | That URL is now the edition's *name* rather than its date:
    | `/be-nl/tips/vondsten-voor-thuiswerkers`, not `/be-nl/daily/2026-08-29`.
    | A date tells a reader nothing and a search engine less.
    |
    | The segment is one word for every market — `tips` — because a path
    | segment is read by a person deciding whether to click and by a search
    | engine deciding what the page is about, and "daily" did that job in
    | none of the markets. It was briefly localised per market
    | (`cadeau-van-de-dag`, `cadeau-du-jour`, `regalo-del-dia`) and collapsed
    | back to one word within hours; see Market::coveSegment() for why.
    |
    | These routes are declared once under the {market} prefix, so the segment
    | cannot be a literal: the pattern admits the current word plus every
    | retired spelling in Market::HISTORICAL_SEGMENTS, and the controller
    | redirects anything that is not the current word to it. Without that
    | check /es/cadeau-van-de-dag/... would resolve, which is one market's
    | page on another's address — duplicate content carrying the wrong
    | hreflang.
    |
    | The dated form is registered BEFORE the slug form, or `2026-08-29` is
    | swallowed as a perfectly valid slug and 404s.
    |
    | Everything under /daily/ is kept forever. Three months of digest emails
    | and everything already indexed point there, and the archive is the whole
    | reason the column has permanent URLs. Each legacy route redirects in ONE
    | hop to its final destination rather than bouncing through the new dated
    | form — a chain costs link equity and reads as sloppy in an audit.
    */
    $coveSegment = implode('|', array_map(
        static fn (string $segment): string => preg_quote($segment, '/'),
        Market::coveSegments(),
    ));

    Route::get('/{cove}', DailyCoveController::class)
        ->middleware(CacheAnonymousPage::ALIAS)
        ->where('cove', $coveSegment)
        ->name('daily');

    Route::get('/{cove}/{date}', [DailyCoveController::class, 'dated'])
        ->middleware(CacheAnonymousPage::ALIAS)
        ->where(['cove' => $coveSegment, 'date' => '\d{4}-\d{2}-\d{2}'])
        ->name('daily.dated');

    Route::get('/{cove}/{slug}', DailyCoveController::class)
        ->middleware(CacheAnonymousPage::ALIAS)
        ->where(['cove' => $coveSegment, 'slug' => '[a-z0-9-]+'])
        ->name('daily.edition');

    Route::get('/daily', [DailyCoveController::class, 'moved'])->name('daily.legacy');

    Route::get('/daily/{date}', [DailyCoveController::class, 'legacyDated'])
        ->where('date', '\d{4}-\d{2}-\d{2}')
        ->name('daily.legacy.dated');

    Route::get('/daily/{slug}', [DailyCoveController::class, 'moved'])
        ->where('slug', '[a-z0-9-]+')
        ->name('daily.legacy.edition');

    /*
    |----------------------------------------------------------------------
    | Gift personas
    |----------------------------------------------------------------------
    |
    | The Coves that are about a person rather than a day: "the cottagecore
    | herbalist", "the dad who has everything". Built by the same builder from
    | the same curated plan; addressed by a permanent slug because a persona
    | never stops being current, so it has no date to be found by.
    |
    | Under /gift-ideas rather than /coves/{slug}: /coves/subscribe, /confirm
    | and /unsubscribe already live there, and a slug catch-all beside them
    | would shadow all three the first time somebody named a persona
    | "subscribe".
    */
    Route::get('/gift-ideas', [GiftIdeasController::class, 'index'])
        ->middleware(CacheAnonymousPage::ALIAS)->name('gift-ideas');

    /*
     * Gift landing pages: "gift ideas for dad who loves cooking" at
     * /be-nl/gift-ideas/for/papa/koken, in each market's own words
     * (App\Services\Gift\BriefUrl). Only the pairs PlanGiftLandingPages
     * recorded exist; the rest 404. The `for` segment keeps them clear of the
     * persona slugs below, which are a single segment after /gift-ideas.
     * See docs/features/gift-landing-pages.md.
     */
    Route::get('/gift-ideas/for/{recipient}/{interest?}', GiftLandingController::class)
        ->middleware(CacheAnonymousPage::ALIAS)
        ->where(['recipient' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'interest' => '[a-z0-9]+(?:-[a-z0-9]+)*'])
        ->name('gift-ideas.landing');

    // Gifts for an occasion (Moederdag, a housewarming): the persona page at
    // its own address, clear of the persona slugs. See CoveKind::Occasion.
    Route::get('/gift-ideas/occasion/{slug}', [GiftIdeasController::class, 'occasion'])
        ->middleware(CacheAnonymousPage::ALIAS)
        ->where('slug', '[a-z0-9-]+')
        ->name('gift-ideas.occasion');

    Route::get('/gift-ideas/{slug}', [GiftIdeasController::class, 'show'])
        ->middleware(CacheAnonymousPage::ALIAS)
        ->where('slug', '[a-z0-9-]+')
        ->name('gift-ideas.persona');

    /*
    |----------------------------------------------------------------------
    | All Coves
    |----------------------------------------------------------------------
    |
    | Every Cove this market has published, in one page. The three kinds each
    | had an index of their own — /daily, /gift-ideas, /guides — and nothing
    | held all of them, so the one word the whole product is named after pointed
    | at four different rooms.
    |
    | A literal segment, never a {slug} catch-all. /coves/subscribe, /confirm
    | and /unsubscribe live below and a catch-all beside them would shadow all
    | three the first time somebody named a Cove "subscribe" — the reason
    | personas are at /gift-ideas rather than here in the first place.
    */
    Route::get('/coves', CovesController::class)
        ->middleware(CacheAnonymousPage::ALIAS)->name('coves');

    /*
     * Community Coves: lists their owners chose to publish. Two literal
     * segments before the slug, so nothing a person calls their Cove can
     * shadow /coves/subscribe or the other routes beside it. See
     * docs/features/community-coves.md.
     */
    Route::get('/coves/community', [CommunityCoveController::class, 'index'])
        ->middleware(CacheAnonymousPage::ALIAS)->name('community');
    Route::get('/coves/community/{slug}', [CommunityCoveController::class, 'show'])
        ->middleware(CacheAnonymousPage::ALIAS)
        ->where('slug', '[a-z0-9-]+')
        ->name('community.show');

    /*
    |----------------------------------------------------------------------
    | Cove subscriptions
    |----------------------------------------------------------------------
    |
    | Double opt-in. A signup sends exactly one email and nothing else until the
    | address confirms — the legal argument is that consent must be demonstrable,
    | and the operational one is that a form anyone can type any address into is
    | a way to mail people who never asked.
    |
    | Unsubscribe is a GET as well as a POST. Email clients cannot POST from a
    | footer link, and a reader who cannot leave in one click marks the mail as
    | spam instead — which costs the sending domain far more than the
    | unsubscribe does. The POST is RFC 8058 one-click, which Gmail and Yahoo
    | require of bulk senders.
    */
    Route::post('/coves/subscribe', [CoveSubscriptionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('coves.subscribe');

    Route::get('/coves/confirm/{token}', [CoveSubscriptionController::class, 'confirm'])
        ->where('token', '[a-f0-9]{64}')
        ->middleware('throttle:30,1')
        ->name('coves.confirm');

    Route::match(['get', 'post'], '/coves/unsubscribe/{token}', [CoveSubscriptionController::class, 'unsubscribe'])
        ->where('token', '[a-f0-9]{64}')
        ->middleware('throttle:30,1')
        ->name('coves.unsubscribe');

    /*
     * "Stop these reminder emails", from the link in every reminder. Signed
     * rather than behind a sign-in, so one click works from any device, and
     * the signature is what stops anybody turning off somebody else's.
     * Exempt from CSRF in bootstrap/app.php for the RFC 8058 POST.
     */
    Route::match(['get', 'post'], '/reminders/stop/{user}', [ReminderEmailController::class, 'stop'])
        ->whereNumber('user')
        ->middleware(['signed', 'throttle:30,1'])
        ->name('reminders.stop');

    /*
     * "Stop these emails", from the link in every email about a friend's
     * question. Signed, like the reminders' link; it turns the email off and
     * leaves the inbox row. See AskPeopleSettingsController.
     */
    Route::match(['get', 'post'], '/ask/people-emails/stop/{user}', [AskPeopleSettingsController::class, 'stopEmails'])
        ->whereNumber('user')
        ->middleware(['signed', 'throttle:30,1'])
        ->name('ask.people-emails.stop');

    /*
     * "This is spam / not asked for?", from every invitation email. Signed and
     * without an account, like the stop links above, but the GET only shows a
     * page with one button: a press counts a complaint against the member who
     * sent it, and mail scanners open every link. The POST is the button and
     * RFC 8058 one-click, exempt from CSRF in bootstrap/app.php.
     * See InviteNotWantedController.
     */
    Route::get('/invites/not-wanted/{inviter}/{hash}', [InviteNotWantedController::class, 'show'])
        ->whereNumber('inviter')
        ->where('hash', '[a-f0-9]{64}')
        ->middleware(['signed', 'throttle:30,1'])
        ->name('invites.not-wanted');

    Route::post('/invites/not-wanted/{inviter}/{hash}', [InviteNotWantedController::class, 'store'])
        ->whereNumber('inviter')
        ->where('hash', '[a-f0-9]{64}')
        ->middleware(['signed', 'throttle:30,1'])
        ->name('invites.not-wanted.store');

    Route::post('/invites/not-wanted/{inviter}/{hash}/undo', [InviteNotWantedController::class, 'undo'])
        ->whereNumber('inviter')
        ->where('hash', '[a-f0-9]{64}')
        ->middleware(['signed', 'throttle:30,1'])
        ->name('invites.not-wanted.undo');

    Route::post('/invites/not-wanted/{inviter}/{hash}/spam', [InviteNotWantedController::class, 'report'])
        ->whereNumber('inviter')
        ->where('hash', '[a-f0-9]{64}')
        ->middleware(['signed', 'throttle:30,1'])
        ->name('invites.not-wanted.spam');

    /*
     * "Uitnodiging aannemen", the button in an invitation email (2026-09-27):
     * creates a new invitee's account and signs them in. The GET only shows a
     * page (mail scanners open every link); the POST is its button, with
     * CSRF. Not behind `guest`, which would bounce a signed-in visitor home
     * without saying why; the controller tells them instead. Throttled like
     * the magic link. See InviteAcceptController.
     */
    Route::get('/invites/accept/{token}', [InviteAcceptController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->middleware('throttle:20,1')
        ->name('invites.accept');

    Route::post('/invites/accept/{token}', [InviteAcceptController::class, 'store'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->middleware('throttle:20,1')
        ->name('invites.accept.store');

    Route::post('/picks/{pick}/react', PickReactionController::class)
        ->whereNumber('pick')
        ->middleware('throttle:60,1')
        ->name('picks.react');

    Route::get('/guides', [GuideController::class, 'index'])
        ->middleware(CacheAnonymousPage::ALIAS)->name('guides');
    Route::get('/guides/{slug}', [GuideController::class, 'show'])
        ->middleware(CacheAnonymousPage::ALIAS)->name('guides.show');

    /*
    |----------------------------------------------------------------------
    | Brand pages
    |----------------------------------------------------------------------
    |
    | A brand page IS a search with the brand preselected — same service, same
    | filters, same cards. What the route buys is the thing a facet URL can never
    | have: one canonical, indexable address per brand per market, with prose
    | above the results.
    |
    | `?brand[]=Sony` stays `noindex` because facet combinations are a
    | crawl-budget trap. `/brand/sony` is the version worth ranking, and it is
    | what every brand link on the site points at.
    |
    | The index exists so this URL space is not orphaned: a crawler that has not
    | seen a search result still finds every brand from one page.
    */
    /*
    |----------------------------------------------------------------------
    | About, privacy, terms
    |----------------------------------------------------------------------
    |
    | Market-prefixed like everything else, because the language differs and
    | because Belgian law wants an imprint reachable from every page — which the
    | footer link is.
    |
    | One route rather than three: they are the same controller reading a
    | different markdown file, and the page name is validated against an
    | allowlist rather than concatenated into a path.
    */
    Route::get('/{page}', LegalController::class)
        ->middleware(CacheAnonymousPage::ALIAS)
        ->whereIn('page', ['about', 'privacy', 'terms'])
        ->name('legal');

    /*
    |----------------------------------------------------------------------
    | Shop Coves
    |----------------------------------------------------------------------
    |
    | The shops this market's prices are compared across. Every offer card on
    | the site names its shop and nothing answered the question that raises —
    | which shops are these? "We compare hundreds of shops" is a claim a visitor
    | cannot check; a list they can scroll is worth more.
    |
    | Membership comes from the feeds table and the connector registry, not from
    | products: a shop onboarded this morning is here before its first ingest
    | finishes, and one switched off last week is gone before its rows are
    | pruned.
    */
    Route::get('/shops', ShopsController::class)
        ->middleware(CacheAnonymousPage::ALIAS)->name('shops');

    /*
    | A Shop Cove: what a shop is like to buy from.
    |
    | Rendered by GuideController — it is an article in every respect the page
    | cares about — but addressed here rather than under /guides, because a
    | piece about a shop belongs above the directory of shops rather than in
    | the archive of buying guides.
    |
    | Registered after the literal /shops so the index cannot be captured by
    | the slug pattern, and the pattern is Str::slug()'s output so anything
    | else is a probe rejected at the router.
    */
    Route::get('/shops/{slug}', [GuideController::class, 'shop'])
        ->middleware(CacheAnonymousPage::ALIAS)
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('shops.show');

    Route::get('/brands', [BrandController::class, 'index'])
        ->middleware(CacheAnonymousPage::ALIAS)->name('brands');
    Route::get('/brand/{slug}', [BrandController::class, 'show'])
        ->middleware(CacheAnonymousPage::ALIAS)
        // Slugs are what Str::slug() produces, so anything else is a probe
        // rather than a link — rejected at the router, not in the database.
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('brand');

    /*
    |----------------------------------------------------------------------
    | Social cards
    |----------------------------------------------------------------------
    |
    | The 1200×630 image a shared link turns into, drawn per page from the
    | record behind it. Every route takes an id or a slug and reads its own
    | text: an endpoint that renders words from the query string would let
    | anyone publish "GiftCoves says ..." on our own domain.
    |
    | Throttled, and for the product card the throttle is the only bound there
    | is. Product cards are never cached — 113,626 of them held 6.21GB of Redis
    | on 2026-09-02 and took the box into swap — so every request for one draws
    | at 1200×630, measured at 58ms. Sixty a minute is about 3.5 CPU-seconds per
    | minute per client: far more than every scraper on earth needs, and far
    | less than a useful amplification vector. Raising it replaces the only
    | ceiling on how fast a crawler can make us draw.
    |
    | The other cards are cached for a month, keyed on the text they draw and
    | the commit that rendered them — not on updated_at, which was the first
    | version of this key and wrong twice over. See the controller.
    |
    | Stateless: no session, no cookies (App\Http\StatelessRoutes). SetMarket
    | still runs, because a card draws in its market's language.
    */
    Route::middleware('throttle:60,1')->withoutMiddleware(StatelessRoutes::SKIPPED)->group(function (): void {
        Route::get('/og/default.png', [OgImageController::class, 'default'])->name('og.default');

        Route::get('/og/p/{group}.png', [OgImageController::class, 'product'])
            ->whereNumber('group')
            ->name('og.product');

        Route::get('/og/guide/{slug}.png', [OgImageController::class, 'guide'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('og.guide');

        Route::get('/og/daily/{date}.png', [OgImageController::class, 'daily'])
            ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
            ->name('og.daily');

        Route::get('/og/brand/{slug}.png', [OgImageController::class, 'brand'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('og.brand');

        // A shared list's card, addressed by the same code as the page. The
        // code is the access, so a card is only drawn for a list that is
        // actually shared — see the controller.
        Route::get('/og/l/{token}.png', [OgImageController::class, 'list'])
            ->where('token', ShareCode::pattern())
            ->name('og.list');
    });

    /*
    |----------------------------------------------------------------------
    | Barcode scanner
    |----------------------------------------------------------------------
    |
    | Scan in a shop, find out whether it is cheaper elsewhere. Nearly free:
    | product_groups is unique on (market, identity_key) and for an EAN-grouped
    | product that key IS the GTIN, so a scan is one unique-index hit.
    */
    Route::get('/scan', [ScanController::class, 'show'])->name('scan');
    Route::get('/scan/{barcode}', [ScanController::class, 'resolve'])
        // Digits only. A camera misread is rejected at the router rather than
        // becoming a database lookup for a string of noise.
        ->where('barcode', '[0-9]{8,14}')
        ->middleware('throttle:120,1')
        ->name('scan.resolve');
    //   /{market}/daily                      today's picks
    //   /{market}/guides                     buying guides

    /*
     * Anything else under a real market.
     *
     * Last in the group, because a fallback matches whatever the routes above
     * did not. It exists so a dead address keeps its market: the global
     * fallback below cannot, since the URL it matches has no {market} segment
     * for SetMarket to read, and a Dutch visitor following a dead Dutch link
     * should not land on an English page.
     *
     * `Route::pattern('market', ...)` still applies, so this catches
     * `/be-nl/anything` and never `/anything/else`.
     */
    Route::fallback(NotFoundController::class)->name('not-found');
});

/*
 * Everything else on the site.
 *
 * Registered after every group, and Laravel keeps a fallback last however it is
 * ordered. It runs the web middleware, which is the whole reason the 404 page is
 * reached through a route rather than rendered from the exception handler: an
 * unmatched URL never reaches middleware, so `CurrentMarket` would be unbound
 * and the Inertia layout would have no market, language or navigation.
 *
 * The market here is `Market::default()` — there is no segment to read one from.
 *
 * It also carries the v1 redirect check that used to live in the exception
 * handler, because a fallback route means unmatched URLs no longer raise
 * NotFoundHttpException at all. See NotFoundController.
 */
Route::fallback(NotFoundController::class)->name('not-found.unprefixed');
