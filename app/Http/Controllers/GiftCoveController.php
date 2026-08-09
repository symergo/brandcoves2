<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListKind;
use App\Models\Recipient;
use App\Models\SecretSantaGroup;
use App\Models\SecretSantaMember;
use App\Models\Wishlist;
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
        $owner = Owner::fromRequest($request);
        $user = $request->user();

        // Everyone gets "My wishlist", created here on the first visit rather
        // than on the first save — a page describing lists should not describe
        // one you do not have yet.
        $mine = $owner->exists() ? app(DefaultList::class)->for($owner, $current) : null;

        $lists = $owner->exists()
            ? $owner->scope(Wishlist::query())
                ->where('market', $current->value())
                ->withCount('items')
                ->get()
            : collect();

        return Inertia::render('GiftCove', [
            'signedIn' => $owner->isSignedIn(),

            'mine' => $mine === null ? null : [
                'id' => $mine->id,
                'title' => $mine->title,
                'items' => $mine->items()->count(),
                'shared' => $mine->visibility?->isShareable() ?? false,
                'url' => $current->url("lists/{$mine->id}"),
            ],

            'counts' => [
                'giftLists' => $lists->where('kind', ListKind::ForSomeone)->count(),
                'people' => $owner->exists() ? $owner->scope(Recipient::query())->count() : 0,
                'registries' => $lists->whereNotNull('event_type')->count(),
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
                'gift' => $current->url('gift'),
                'lists' => $current->url('lists'),
                'santa' => $current->url('santa'),
            ],
        ]);
    }
}
