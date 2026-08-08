<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Guide;
use App\Models\ProductGroup;
use App\Support\CurrentMarket;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(CurrentMarket $current): Response
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

            // The evergreen half. Coves earn their traffic over years, so the
            // front page is where a first-time visitor discovers the archive
            // exists at all.
            'coves' => $this->coves($current),
        ]);
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
            'hasPuzzle' => $edition->challenge_group_id !== null,
            // A few, not all: the front page is an invitation to the edition,
            // not a copy of it.
            'finds' => $edition->picks
                ->filter(fn (DailyPick $pick) => $pick->group !== null)
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
