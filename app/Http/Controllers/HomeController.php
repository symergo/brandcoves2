<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Wishlist;
use App\Services\Search\AmazonEvent;
use App\Services\Seo\PageMeta;
use App\Services\Social\MyPeople;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /** How many of each the band shows; the rest is one click away on its own page. */
    private const MINE_SHOWN = 6;

    public function __invoke(Request $request, CurrentMarket $current, MyPeople $people): Response
    {
        /*
         * No catalogue counters.
         *
         * They were scaffolding — three COUNT(*) queries per homepage request,
         * on the largest table we have, to render a stat row that told a
         * shopper how big our warehouse is. Removed with the section that
         * displayed them; see the note in `Home.tsx`.
         */
        /*
         * The home page had no PageMeta at all, so it shipped with no meta
         * description and no og:title — the one page most likely to be linked
         * from outside was the one whose social card had no words on it.
         */
        app(PageMeta::class)->set(
            title: __('site.home.title'),
            description: __('site.home.seo_description'),
            canonical: url($current->url()),
        )->social(
            // A shared link says what the front page says (2026-10-04):
            // "GiftCoves - het sociale cadeaunetwerk", which is what the
            // default card beside it shows, and the hero's subtitle. The
            // search listing above keeps its keywords.
            title: __('site.og.default_title'),
            description: __('site.home.hero_subtitle'),
        );

        return Inertia::render('Home', [
            /*
             * Today's Cove, on the front page.
             *
             * The thing that makes someone come back tomorrow should not be one
             * click deep. A visitor who lands on the home page and sees a dated
             * edition with real finds learns that this site changes; one who
             * sees a search box learns it is a search engine.
             */
            'today' => $this->today($current),

            /*
             * Yours, when you are signed in (owner, 2026-11-07): the Coves you
             * changed last and your people, nearest date first. Null for a
             * visitor, which is also what keeps this page in the anonymous page
             * cache: that cache only serves requests nobody is signed in to, so
             * a personal answer never reaches somebody else.
             */
            'mine' => $this->mine($request, $current, $people),

            // An Amazon sales event under the hero while it runs (AmazonEvent).
            'amazonEvent' => AmazonEvent::current($current->get()),

            /*
             * No list wizard since the 2026-09-26 redesign. It was mounted here
             * on 2026-09-13; the new page asks one thing (Create a Cove) and
             * the wizard is where that button leads, on My Coves.
             */

            /*
             * No lists of Coves since 2026-09-29: the band is one card per kind
             * of Cove, drawn by the page from its own links (owner). The random
             * shelf ('coves') and the newest Community Coves ('collected') went
             * with the lists they fed.
             */
        ]);
    }

    /**
     * Your own Coves on this market, last changed first, and your people.
     *
     * Only lists you own: a list somebody shared with you is on Mijn Coves,
     * where it is marked as theirs. The people are My people's rows, in its
     * order (nearest birthday or occasion first), so the two pages agree.
     *
     * @return array{coves: list<array<string, mixed>>, people: list<array<string, mixed>>}|null
     */
    private function mine(Request $request, CurrentMarket $current, MyPeople $people): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $owner = Owner::fromRequest($request);
        $language = $current->get()->language();

        $coves = $owner->scope(Wishlist::query())
            ->where('market', $current->get()->value)
            ->with(['items' => fn ($q) => $q->whereNotNull('snapshot_image_url')->latest('created_at')->limit(4)])
            ->withCount('items')
            ->latest('updated_at')
            ->limit(self::MINE_SHOWN)
            ->get()
            ->map(fn (Wishlist $list) => [
                'id' => $list->id,
                'title' => $list->displayTitle($language),
                'kind' => $list->kind->value,
                'count' => $list->items_count,
                'covers' => $list->items->pluck('snapshot_image_url')->all(),
                'url' => $current->url("lists/{$list->id}"),
            ])
            ->values()
            ->all();

        return [
            'coves' => $coves,
            'people' => array_slice($people->for($user, $current), 0, self::MINE_SHOWN),
        ];
    }

    /** @return array<string, mixed>|null */
    private function today(CurrentMarket $current): ?array
    {
        $edition = DailyPickSet::query()
            ->forMarket($current->get())
            // daily(), and not for tidiness: a persona has no drop date, and
            // Postgres sorts DESC with NULLS FIRST — so without this the newest
            // gift persona is served as today's edition, on the front page.
            ->daily()
            ->published()
            ->with(['picks.group'])
            ->orderByDesc('drop_date')
            ->first();

        if ($edition === null) {
            return null;
        }

        return [
            'theme' => $edition->theme_title,
            'blurb' => $edition->theme_blurb,
            'date' => $edition->drop_date->toDateString(),
            'label' => $edition->drop_date->format('j M'),
            'url' => $current->get()->covePath(),
            // A few, not all: the front page is an invitation to the edition,
            // not a copy of it.
            'finds' => $edition->picks
                // In stock, like everywhere else: the front page is the first
                // thing a visitor sees, and an unbuyable product is a worse
                // first impression than one fewer card.
                ->filter(fn (DailyPick $pick) => $pick->group !== null && $pick->group->in_stock)
                ->take(4)
                ->map(fn (DailyPick $pick) => [
                    'id' => $pick->group->id,
                    'title' => $pick->group->displayTitle(),
                    'image' => $pick->group->image_url,
                    'price' => $pick->group->min_price,
                    'url' => $current->url("p/{$pick->group->id}/{$pick->group->slug}"),
                ])
                ->values()
                ->all(),
        ];
    }
}
