<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RecipientType;
use App\Services\Seo\PageMeta;
use App\Services\Social\MyPeople;
use App\Support\CurrentMarket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My people": everybody you buy for, saved people and friends on one list.
 *
 * Open to guests, who get what the page is for and a way to sign in, the way
 * the lists page and Secret Friend do: a link to this page in a menu or an
 * email should never land somebody on a bare login form. Nothing about anybody
 * is sent to a guest. Never indexed: it is a page about one account's people.
 *
 * The merge itself is in {@see MyPeople}; see docs/features/my-people.md for
 * why there is one page rather than two.
 */
class PeopleController extends Controller
{
    public function index(Request $request, CurrentMarket $current, MyPeople $people): Response
    {
        app(PageMeta::class)->set(title: __('site.people.title'), robots: 'noindex, nofollow');

        $user = $request->user();

        return Inertia::render('People/Index', [
            'isSignedIn' => $user !== null,
            'people' => $user === null ? [] : $people->for($user, $current),
            'settings' => $user === null ? null : $people->settings($user),
            /*
             * The closed vocabulary for "who is it to you", the same one the
             * Gift Finder offers. Stored as its value ("mother") so the Gift
             * Finder and the gift landing pages read it without guessing;
             * a relationship typed elsewhere as free text still shows as typed.
             */
            'relationships' => array_map(fn (RecipientType $type) => [
                'value' => $type->value,
                'label' => __("site.gift.relationships.{$type->value}"),
            ], RecipientType::cases()),
        ]);
    }
}
