<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Notification;
use App\Services\Seo\PageMeta;
use App\Services\Wishlist\AddingMode;
use App\Services\Wishlist\ListOptions;
use App\Support\Analytics;
use App\Support\CookieConsent;
use App\Support\CurrentMarket;
use App\Support\MarketSwitcher;
use App\Support\Owner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Bust the client cache when translations change.
     *
     * Without this a visitor who has the page cached keeps the old strings
     * after a copy fix, and the Inertia asset version alone would not notice
     * because no JS or CSS changed.
     */
    private function translationVersion(): string
    {
        return (string) filemtime(lang_path(app()->getLocale().'/site.php'));
    }

    /**
     * Shared with every Inertia page.
     *
     * Kept deliberately small — this payload is serialised into every single
     * response, including partial reloads.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $market = app(CurrentMarket::class)->get();

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $request->user() === null ? null : [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'isAdmin' => $request->user()->is_admin,
                ],

                /*
                 * Shared rather than a prop of the login page, because signing
                 * in is no longer only something that happens *on* that page:
                 * `SignInDialog` offers the same two ways from wherever
                 * somebody hits the wall, and it must not offer a Google button
                 * that leads to an exception when the client id is unset.
                 *
                 * A config read per request, on a page most visitors see once.
                 */
                'googleEnabled' => filled(config('services.google.client_id')),
            ],

            /*
             * The Google tag, and whether this visitor has agreed to it.
             *
             * `id` is null wherever the tag is switched off — staging, local,
             * an environment that cleared GA_MEASUREMENT_ID — and that null is
             * what stops the banner appearing there. A cookie banner on a site
             * that sets no non-essential cookie is theatre, and it trains
             * people to dismiss the ones that mean something.
             *
             * The id is public by nature; it ships in the page source of every
             * site that uses one, so there is nothing here a visitor could not
             * already read. Shipping it lets the banner start reporting the
             * page the visitor accepted *on*, rather than the next one.
             */
            'analytics' => [
                'id' => Analytics::measurementId(),
                'consent' => CookieConsent::state($request),
            ],

            /*
             * The canonical URL of this page, for the client to write into the
             * <link rel="canonical"> the Blade shell rendered.
             *
             * That tag is emitted once, server-side, and Inertia's <Head> only
             * manages the title — so on every client-side navigation the head
             * kept advertising the canonical of whichever page the visitor
             * first landed on. The address bar moved; the tag did not.
             *
             * That is not merely untidy, because iOS share sheets read the
             * canonical link in preference to the address bar. Sharing a
             * product you had tapped through to sent the *entry* page's URL to
             * WhatsApp, which then drew that page's card — a previous link and
             * a previous card, every time, and nothing about it looked like a
             * front-end bug. Reported 2026-09-02.
             *
             * Mirrors the Blade fallback exactly (see app.blade.php), so the
             * value the client writes is the value the server would have
             * rendered had this been a full page load.
             */
            'canonical' => fn (): string => app(PageMeta::class)->toArray()['canonical']
                ?? url($request->path()),

            'market' => [
                'key' => $market->value,
                'label' => $market->label(),
                'language' => $market->language(),
                'hrefLang' => $market->hrefLang(),
                'currency' => $market->currency(),
                /*
                 * The Daily Cove's URL segment, in this market's language.
                 *
                 * Shared because the header, the footer and two Cove rails all
                 * link to it, and the alternative is five components agreeing on
                 * a translation table that the server already owns. When it
                 * disagrees, the link 404s — the route deliberately refuses
                 * another market's word.
                 */
                'coveSegment' => $market->coveSegment(),
            ],

            /*
             * The switcher, as two axes rather than one list of pairs: a country
             * with a flag, and the languages that country can be read in. The
             * shape and the reasoning are in App\Support\MarketSwitcher.
             *
             * Still a short fixed list, cheaper to ship than to fetch. An
             * unpublished market is left out rather than disabled: a greyed-out
             * country reads as a fault, and there is nothing the visitor can do
             * about it.
             */
            'markets' => app(MarketSwitcher::class)->payload(),

            /*
             * Site copy for the current market's language.
             *
             * Shipped whole rather than fetched: it is a few kilobytes, and a
             * separate request would mean the first paint shows translation
             * keys. Keyed by language, so be-nl and nl-nl share one file —
             * they are two markets, not two languages.
             */
            'translations' => Lang::get('site'),
            'translationVersion' => $this->translationVersion(),

            /*
             * Unread badge count.
             *
             * A closure so it costs nothing for the anonymous majority, and one
             * indexed count for everyone else — notifications is indexed on
             * (user_id, read_at, created_at), so this is an index-only scan.
             */
            'unreadCount' => fn () => $request->user() === null
                ? 0
                : Notification::query()->where('user_id', $request->user()->id)->unread()->count(),

            /*
             * The list currently being filled, or null.
             *
             * A closure for the same reason `unreadCount` is one: it costs
             * nothing for everybody who is not in adding mode, which is almost
             * everybody almost always. When it is on, it is one primary-key
             * lookup, and it is worth paying on every page because the mode has
             * to be *visible* on every page — an invisible mode that quietly
             * redirects saves is worse than no mode at all.
             *
             * It is no longer the only wishlist data here; see `lists` below.
             */
            'savingTo' => fn () => app(AddingMode::class)->current(Owner::fromRequest($request)),

            /*
             * The lists the save picker offers, so it does not have to ask.
             *
             * This reverses the note that used to sit above `savingTo` — that
             * `savedItems.ts` fetches everything else lazily so pages with no
             * cards pay nothing. The reason it changed: the picker fetched
             * `/list-options` **on every open**, per card, and that request sat
             * between pressing the chevron and seeing the rows. A page where
             * somebody opens three pickers made three identical requests for a
             * fact about them that had not changed since they signed in.
             *
             * Signed in only, so the anonymous majority still pays nothing —
             * the same reasoning as `unreadCount`, and the picker is
             * unreachable while signed out anyway (`requireAccount()` opens the
             * sign-in dialog instead). For everybody else it is one indexed
             * query per response, against N fetches per page, and it arrives in
             * a payload that was being sent regardless.
             *
             * Which list holds a given product is deliberately *not* here: that
             * is a fact about the product rather than about the person, and it
             * rides with the rest of them in `savedItems`.
             */
            'lists' => fn () => $request->user() === null
                ? []
                : ListOptions::forPicker(Owner::fromRequest($request)),

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Neutral outcome messages — the Cove signup deliberately says
                // the same thing however it went, so it is neither a success nor
                // an error and calling it either would leak which one it was.
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }
}
