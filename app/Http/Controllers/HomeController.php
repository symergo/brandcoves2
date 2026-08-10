<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Guide;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\SecretSantaGroup;
use App\Models\Wishlist;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, CurrentMarket $current): Response
    {
        $market = $current->get();

        return Inertia::render('Home', [
            'stats' => [
                // Real counts, so the scaffold tells the truth about how empty
                // the catalogue currently is rather than showing placeholders.
                'products' => ProductGroup::query()->forMarket($market)->count(),
                'comparable' => ProductGroup::query()->forMarket($market)->comparable()->count(),
                'guides' => Guide::query()->forMarket($market)->published()->count(),
            ],

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
             * The gifting band.
             *
             * The homepage said "you don't know what you want, you know who it's
             * for" and then offered exactly one way to act on it. Everything
             * else gifting can do — a list somebody else fills in, a Secret
             * Santa, a quiz over a list — was reachable only by already knowing
             * the URL, which is how v1 shipped its gift finder unlinked and
             * unreachable for two months.
             *
             * Counts rather than prose where the visitor already has something:
             * "3 lists" is a reason to click and "Make a list" is not, once the
             * lists exist.
             */
            'gifting' => $this->gifting($request, $current),

            // The evergreen half. Coves earn their traffic over years, so the
            // front page is where a first-time visitor discovers the archive
            // exists at all.
            'coves' => $this->coves($current),
        ]);
    }

    /**
     * The four ways in, and what this visitor already has.
     *
     * @return array<string, mixed>
     */
    private function gifting(Request $request, CurrentMarket $current): array
    {
        $owner = Owner::fromRequest($request);
        $user = $request->user();

        return [
            // Anonymous-first, exactly like the lists themselves: someone who
            // saved a product before signing up should see it here.
            'lists' => $owner->exists()
                ? $owner->scope(Wishlist::query())
                    ->where('market', $current->value())
                    ->count()
                : 0,

            'people' => $owner->exists()
                ? $owner->scope(Recipient::query())->count()
                : 0,

            // Signed-in only: a group has to belong to somebody who can be
            // reached when the draw happens.
            'santaGroups' => $user === null
                ? 0
                : SecretSantaGroup::query()
                    ->where('market', $current->value())
                    ->where('owner_user_id', $user->id)
                    ->count(),

            'urls' => [
                'gift' => $current->url('gift'),
                'lists' => $current->url('lists'),
                'santa' => $current->url('santa'),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function today(CurrentMarket $current): ?array
    {
        $edition = DailyPickSet::query()
            ->forMarket($current->get())
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
            'url' => $current->url('daily'),
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
                    'title' => $pick->group->title,
                    'image' => $pick->group->image_url,
                    'price' => $pick->group->min_price,
                    'url' => $current->url("p/{$pick->group->id}/{$pick->group->slug}"),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function coves(CurrentMarket $current): array
    {
        return Guide::query()
            ->forMarket($current->get())
            ->published()
            ->orderByDesc('published_at')
            ->limit(6)
            ->get(['slug', 'title', 'intro', 'source_volume'])
            ->map(fn (Guide $guide) => [
                'title' => $guide->title,
                'intro' => $guide->intro,
                'url' => $current->url("guides/{$guide->slug}"),
                // Why it exists, and a fact no competitor has.
                'searches' => $guide->source_volume,
            ])
            ->all();
    }
}
