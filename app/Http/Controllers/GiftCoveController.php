<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListKind;
use App\Models\Recipient;
use App\Models\SecretSantaGroup;
use App\Models\SecretSantaMember;
use App\Models\Wishlist;
use App\Services\Seo\PageMeta;
use App\Services\Wishlist\DefaultList;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Gift Cove: one place that explains every gifting tool and shows what you
 * already have.
 *
 * These features accumulated one at a time and were each reachable from
 * somewhere different — the wizard from the nav, Secret Santa from its own
 * page, lists from the header, the quiz from inside a list, handover and
 * registries from nowhere at all. Individually discoverable, collectively
 * invisible: nobody could see that they were parts of one thing.
 *
 * Deliberately **explains as well as links**. "Secret Santa" is self-evident;
 * "a list you build for somebody and then hand to them" is not, and a tool
 * nobody understands is a tool nobody opens.
 *
 * Public, not auth-gated: somebody has to be able to read what this offers
 * before deciding to sign up. Counts simply come back as zero.
 */
class GiftCoveController extends Controller
{
    public function __invoke(Request $request, CurrentMarket $current): Response
    {
        app(PageMeta::class)->set(
            title: __('site.gift_cove.seo_title'),
            description: __('site.gift_cove.seo_description'),
            canonical: url($current->url('gift-cove')),
        );

        $owner = Owner::fromRequest($request);
        $user = $request->user();

        // Everyone gets "My wishlist", created here on the first visit rather
        // than on the first save — a page describing lists should not describe
        // one you do not have yet.
        if ($owner->isSignedIn()) {
            app(DefaultList::class)->for($owner, $current);
        }

        $lists = $owner->exists()
            ? $owner->scope(Wishlist::query())
                ->where('market', $current->value())
                ->withCount('items')
                ->get()
            : collect();

        /*
         * Wishlists, plural.
         *
         * There is still exactly one *default* — the list a one-tap save lands
         * in, so "where did my save go?" keeps its single answer — but that was
         * being rendered as though it were the only list a person may keep for
         * themselves. It never was: the picker and the create form have always
         * made more, and this page then showed one of them and hid the rest,
         * which reads as a limit rather than as an omission.
         *
         * Occasions are the reason it matters. A registry is an ordinary wish
         * list with a date on it, so "my wedding" and "things I want" are two
         * lists for me, not one list I have to choose between.
         *
         * Default first, then most recently touched: the one a save lands in
         * without being asked about is the one that has to be at the top.
         */
        $wishlists = $lists
            ->where('kind', ListKind::Mine)
            ->sortByDesc(fn (Wishlist $list) => $list->updated_at?->getTimestamp() ?? 0)
            // Two passes rather than one composite key: PHP's sorts are
            // stable, so the recency order survives inside each half.
            ->sortByDesc(fn (Wishlist $list) => $list->is_default ? 1 : 0)
            ->values()
            ->map(fn (Wishlist $list) => [
                'id' => $list->id,
                'title' => $list->displayTitle(),
                'items' => $list->items_count,
                'shared' => $list->visibility?->isShareable() ?? false,
                'isDefault' => (bool) $list->is_default,

                /*
                 * The occasion, where there is one. This is the whole visible
                 * difference between two wish lists of mine, and without it a
                 * registry for a wedding and a running list of things I want
                 * are two cards with two titles and nothing to tell them apart.
                 */
                'occasion' => $list->event_type?->label(),
                'occasionDate' => $list->event_date?->toDateString(),
                'url' => $current->url("lists/{$list->id}"),
            ])
            ->all();

        return Inertia::render('GiftCove', [
            'signedIn' => $owner->isSignedIn(),

            'wishlists' => $wishlists,

            'counts' => [
                'giftLists' => $lists->where('kind', ListKind::ForSomeone)->count(),

                /*
                 * Group lists were creatable and uncounted.
                 *
                 * `giftLists` is `ForSomeone` alone, so a page whose whole job
                 * is showing what you already have could not show that a group
                 * gift existed — the one kind of list that is useless until
                 * other people are on it, and therefore the one most worth
                 * being reminded of.
                 */
                'groupLists' => $lists->where('kind', ListKind::Group)->count(),
                'people' => $owner->exists() ? $owner->scope(Recipient::query())->count() : 0,
                /*
                 * Registries proper, not every list with an occasion.
                 *
                 * This counted `event_type` alone, which was the same thing
                 * while only a wish list could carry one. Now that any kind
                 * can, an unrestricted count would badge the *registry* tool
                 * with the number of gift lists you have put a birthday on —
                 * and a registry is specifically your own list, with a date and
                 * an address, that people post things to.
                 */
                'registries' => $lists
                    ->whereNotNull('event_type')
                    ->where('kind', ListKind::Mine)
                    ->count(),
                'santa' => $user === null
                    ? 0
                    : SecretSantaMember::query()->where('user_id', $user->id)->count(),
                'suggestions' => $owner->exists()
                    ? Wishlist::query()
                        ->whereIn('id', $lists->pluck('id'))
                        ->withCount('suggestions')
                        ->get()
                        ->sum('suggestions_count')
                    : 0,
            ],

            'santaGroups' => $user === null
                ? []
                : SecretSantaGroup::query()
                    ->where('market', $current->value())
                    ->whereExists(fn ($q) => $q
                        ->selectRaw('1')
                        ->from('secret_santa_members')
                        ->whereColumn('secret_santa_members.group_id', 'secret_santa_groups.id')
                        ->where('secret_santa_members.user_id', $user->id))
                    ->latest()
                    ->limit(5)
                    ->get()
                    ->map(fn (SecretSantaGroup $group) => [
                        'title' => $group->title,
                        'drawn' => $group->status->isDrawn(),
                        'url' => $current->url("santa/{$group->id}"),
                    ])
                    ->all(),

            'urls' => [
                // "How each one works" is its own page rather than the bottom
                // half of this one; see GiftCoveManualController.
                'manual' => $current->url('gift-cove/how-it-works'),
                'gift' => $current->url('gift'),
                'lists' => $current->url('lists'),
                'santa' => $current->url('santa'),
            ],
        ]);
    }
}
