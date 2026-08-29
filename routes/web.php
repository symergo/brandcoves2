<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AskController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\ClickBeaconController;
use App\Http\Controllers\ClickOutController;
use App\Http\Controllers\CovesController;
use App\Http\Controllers\CoveSubscriptionController;
use App\Http\Controllers\DailyCoveController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\DiscoverCoveController;
use App\Http\Controllers\GiftController;
use App\Http\Controllers\GiftCoveController;
use App\Http\Controllers\GiftCoveManualController;
use App\Http\Controllers\GiftIdeasController;
use App\Http\Controllers\GiftPledgeController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\HandoverController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\ListInvitationController;
use App\Http\Controllers\ListItemVoteController;
use App\Http\Controllers\ListQuizController;
use App\Http\Controllers\MarketPreferenceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OgImageController;
use App\Http\Controllers\PickReactionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RecipientController;
use App\Http\Controllers\RecipientProfileController;
use App\Http\Controllers\SaveIntentController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SearchHelpController;
use App\Http\Controllers\SecretSantaController;
use App\Http\Controllers\SerendipityController;
use App\Http\Controllers\SharedListController;
use App\Http\Controllers\ShopsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SuggestionController;
use App\Http\Controllers\WishlistCollaboratorController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\WishlistItemController;
use App\Support\MarketPreference;
use Illuminate\Http\Request;
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

// Where the switcher posts a choice. Unprefixed and POST-only; the reasoning is
// in MarketPreferenceController.
Route::post('/market', MarketPreferenceController::class)->name('market.choose');

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

Route::prefix('{market}')->group(function () {
    Route::get('/', HomeController::class)->name('home');

    Route::get('/search', SearchController::class)->name('search');

    // What the box accepts and how the camera does it. Next to /search rather
    // than with about/privacy/terms: it is documentation of a tool, not a
    // document about the company, and the only pages that link to it are the
    // ones with a search field on them.
    Route::get('/search-help', SearchHelpController::class)->name('search-help');

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
    | Reading stays open, and so does claiming on somebody else's shared list:
    | the person claiming followed a link once, and making them register to say
    | "I will get this" is how a gift list stops working as a coordination tool.
    */
    Route::get('/lists', [WishlistController::class, 'index'])->name('lists');
    Route::get('/lists/{list}', [WishlistController::class, 'show'])->name('lists.show');

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

    Route::middleware('auth')->group(function () {
        Route::post('/lists', [WishlistController::class, 'store'])->name('lists.store');
        Route::patch('/lists/{list}', [WishlistController::class, 'update'])->name('lists.update');
        Route::delete('/lists/{list}', [WishlistController::class, 'destroy'])->name('lists.destroy');

        Route::post('/list-items', [WishlistItemController::class, 'store'])->name('items.store');
        Route::patch('/list-items/{item}', [WishlistItemController::class, 'update'])->name('items.update');
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
    });

    /*
     * Co-givers. Only the owner manages the roster: a collaborator who could
     * invite more collaborators is a list that quietly grows an audience, and
     * the whole point of a `for_someone` list is that its subject never sees it.
     */
    Route::post('/lists/{list}/collaborators', [WishlistCollaboratorController::class, 'store'])
        ->middleware('auth')
        ->name('lists.collaborators.store');
    Route::delete('/lists/{list}/collaborators/{collaborator}', [WishlistCollaboratorController::class, 'destroy'])
        ->middleware('auth')
        ->name('lists.collaborators.destroy');

    /*
     * Following an invitation to help choose.
     *
     * The token decides which list to land on, never whether access is granted
     * - that happens on sign-in, keyed to the address the invitation was sent
     * to. A link in an email is followed by whoever holds the inbox, and a URL
     * cannot tell a forward from the real recipient.
     */
    Route::get('/invitations/{token}', ListInvitationController::class)
        ->where('token', '[0-9a-f-]{36}')
        ->middleware('throttle:30,1')
        ->name('invitations.show');

    Route::post('/recipients', [RecipientController::class, 'store'])->name('recipients.store');
    Route::patch('/recipients/{recipient}', [RecipientController::class, 'update'])->name('recipients.update');
    Route::delete('/recipients/{recipient}', [RecipientController::class, 'destroy'])->name('recipients.destroy');

    /*
     * Hand a list to the person it was built for.
     *
     * It stops being research and becomes theirs — which is what makes it
     * claimable, and therefore useful to everybody else.
     */
    Route::post('/lists/{list}/handover', [HandoverController::class, 'store'])->name('lists.handover');

    // Suggestions the owner has not decided on yet.
    Route::post('/suggestions/{item}/accept', [SuggestionController::class, 'accept'])->name('suggestions.accept');
    Route::delete('/suggestions/{item}', [SuggestionController::class, 'destroy'])->name('suggestions.destroy');

    // The shared, claimable view. Rate-limited because it is unauthenticated
    // and the token is the only thing guarding it.
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/l/{token}', [SharedListController::class, 'show'])->name('lists.shared');
        Route::post('/l/{token}/claim/{item}', [SharedListController::class, 'claim'])->name('lists.claim');
        Route::delete('/l/{token}/claim/{item}', [SharedListController::class, 'unclaim'])->name('lists.unclaim');
        Route::post('/l/{token}/sent/{item}', [SharedListController::class, 'markSent'])->name('lists.sent');

        // Group gift: pledges against one item, and a suggestion for the owner.
        Route::post('/l/{token}/pledge/{item}', [GiftPledgeController::class, 'store'])->name('lists.pledge');
        Route::delete('/l/{token}/pledge/{item}', [GiftPledgeController::class, 'destroy'])->name('lists.pledge.destroy');

        /*
         * The pot on a group list, which names no item.
         *
         * A group list is one present and the items under it are candidates, so
         * money pledged against one of them would be a bet on an outcome the
         * group has not decided. The controller refuses whichever of these two
         * shapes is wrong for the kind rather than quietly doing the other.
         */
        /*
         * Which present the group should buy.
         *
         * Mounted on the share token beside the pledge routes, because that is
         * how a member reaches a group list at all — most of them have no
         * account, which is the point of the join-by-link design.
         */
        Route::post('/l/{token}/vote/{item}', [ListItemVoteController::class, 'store'])->name('lists.vote');
        Route::delete('/l/{token}/vote/{item}', [ListItemVoteController::class, 'destroy'])->name('lists.vote.destroy');

        Route::post('/l/{token}/pledge', [GiftPledgeController::class, 'store'])->name('lists.pledge.pot');
        Route::delete('/l/{token}/pledge', [GiftPledgeController::class, 'destroy'])->name('lists.pledge.pot.destroy');
        Route::post('/l/{token}/suggest', [SuggestionController::class, 'store'])->name('lists.suggest');
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
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/for/{token}', [RecipientProfileController::class, 'show'])->name('recipients.self');
        Route::post('/for/{token}', [RecipientProfileController::class, 'update'])->name('recipients.self.update');
        Route::post('/for/{token}/claim', [RecipientProfileController::class, 'claim'])->name('recipients.self.claim');
        Route::get('/for/{token}/suggest', [RecipientProfileController::class, 'suggest'])->name('recipients.self.suggest');
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
    Route::middleware('throttle:60,1')->group(function () {
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
    Route::get('/gift-cove/how-it-works', GiftCoveManualController::class)->name('gift-cove.manual');

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
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/gift', [GiftController::class, 'suggest'])->name('gift.suggest');
        Route::post('/gift/swap', [GiftController::class, 'swap'])->name('gift.swap');
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

    Route::middleware(['auth', 'throttle:10,1'])->group(function () {
        Route::post('/ask', [AskController::class, 'store'])->name('ask.store');
        Route::post('/ask/{question}/answers', [AskController::class, 'answer'])
            ->whereNumber('question')
            ->name('ask.answer');
    });

    /*
    |----------------------------------------------------------------------
    | Discovery modes
    |----------------------------------------------------------------------
    |
    | One pipeline, reconfigured by a Mode Profile. The GET landing is
    | deep-linkable and indexable per mode; the POST is what the dial calls as
    | it moves, so the surface reorganises in place rather than navigating.
    |
    | Market-prefixed like everything else: identity, prices and language are
    | all scoped to a market, and an unprefixed discovery route would be the one
    | endpoint that has to resolve it some other way.
    */
    Route::get('/discover/{mode?}', [DiscoverController::class, 'show'])
        ->where('mode', '[a-z-]+')
        ->name('discover');

    Route::middleware('throttle:120,1')->group(function () {
        Route::post('/discover', [DiscoverController::class, 'discover'])->name('discover.run');
        Route::post('/discover/react', [DiscoverController::class, 'react'])->name('discover.react');
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
    | `/be-nl/daily/vondsten-voor-thuiswerkers`, not `/be-nl/daily/2026-08-29`. A
    | date tells a reader nothing and a search engine less.
    |
    | The dated form is registered FIRST and permanently redirects. Three months
    | of digest emails and everything already indexed point at it, so it has to
    | keep resolving — and it has to be matched before the slug route, which
    | would otherwise swallow `2026-08-29` as a perfectly valid slug and 404.
    */
    Route::get('/daily', DailyCoveController::class)->name('daily');

    Route::get('/daily/{date}', [DailyCoveController::class, 'dated'])
        ->where('date', '\d{4}-\d{2}-\d{2}')
        ->name('daily.dated');

    Route::get('/daily/{slug}', DailyCoveController::class)
        ->where('slug', '[a-z0-9-]+')
        ->name('daily.edition');

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
    Route::get('/gift-ideas', [GiftIdeasController::class, 'index'])->name('gift-ideas');
    Route::get('/gift-ideas/{slug}', [GiftIdeasController::class, 'show'])
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
    Route::get('/coves', CovesController::class)->name('coves');

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

    Route::post('/picks/{pick}/react', PickReactionController::class)
        ->whereNumber('pick')
        ->middleware('throttle:60,1')
        ->name('picks.react');

    Route::get('/guides', [GuideController::class, 'index'])->name('guides');
    Route::get('/guides/{slug}', [GuideController::class, 'show'])->name('guides.show');

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
    Route::get('/shops', ShopsController::class)->name('shops');

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
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('shops.show');

    Route::get('/brands', [BrandController::class, 'index'])->name('brands');
    Route::get('/brand/{slug}', [BrandController::class, 'show'])
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
    | Throttled despite being cached. The cache key includes the record's
    | updated_at, so a flood of requests for products nobody has shared is a
    | flood of cache misses, and each miss rasterises type at 1200×630. Sixty a
    | minute is far more than every scraper on earth needs and far less than a
    | useful amplification vector.
    */
    Route::middleware('throttle:60,1')->group(function (): void {
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
});
