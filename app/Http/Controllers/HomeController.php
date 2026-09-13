<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CoveKind;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Services\Seo\PageMeta;
use App\Services\Wishlist\WizardOffer;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, CurrentMarket $current, WizardOffer $offer): Response
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
        );

        $owner = Owner::fromRequest($request);

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
             * The list wizard, on the front page (owner's call, 2026-09-13),
             * where the Organise band and its counts were. Same shape My
             * Lists and the Gift Cove send, from the same service, so the
             * three pages mount one wizard.
             */
            'signedIn' => $owner->isSignedIn(),
            ...$offer->for($owner, $request->user(), $current->get()),

            /*
             * The shelf of people, which the front page never showed.
             *
             * `coves` below is `articles()` — guides, seasonal guides and
             * advice — so a gift persona appeared on `/gift-ideas`, on `/coves`
             * and in the sitemap, and nowhere a first-time visitor would meet
             * one. On a market whose only other Coves are advice articles that
             * made the front page look like a consumer-rights blog.
             *
             * Its own band rather than six more rows in that one. The articles
             * band promises "long reads around a theme" and prints a monthly
             * search volume per card; a persona is neither — it is a person to
             * shop for, it has no search volume, and it is drawn rather than
             * described. Mixing them would have needed the intro to stop saying
             * what the cards are.
             */
            'personas' => $this->personas($current),

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
                    'title' => $pick->group->title,
                    'image' => $pick->group->image_url,
                    'price' => $pick->group->min_price,
                    'url' => $current->url("p/{$pick->group->id}/{$pick->group->slug}"),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Gift personas, newest first.
     *
     * The band that showed these three left the front page on 2026-09-08 at
     * the owner's word. The list still goes to the page because the Discover
     * band shows its card to the persona shelf only when the market has one,
     * and three rows is a cheap way to know that.
     *
     * Ordered by `published_at` like the shelf at `/gift-ideas`, and for the
     * same reason: a persona has no date, and that stamp is set once at first
     * build and never refreshed by a rebuild. Anything else would reshuffle the
     * front page whenever a persona's products were refreshed, which is
     * movement no reader could account for.
     *
     * @return list<array<string, mixed>>
     */
    private function personas(CurrentMarket $current): array
    {
        return DailyPickSet::query()
            ->forMarket($current->get())
            ->personas()
            ->published()
            // The count below walks the picks, so they are loaded rather than
            // counted one persona at a time.
            ->with(['picks.group'])
            ->orderByDesc('published_at')
            ->limit(3)
            ->get()
            ->map(fn (DailyPickSet $persona) => [
                'title' => $persona->theme_title,
                'blurb' => $persona->theme_blurb,
                'url' => $current->url('gift-ideas/'.$persona->slug),
                /*
                 * The drawing, not a product photograph — the same choice the
                 * shelf makes. A cover taken from the first buyable find makes
                 * a row of *people* look like a row of products, and changes
                 * face whenever stock does.
                 */
                'scene' => $persona->scene?->value,
                // In stock only. A count that includes what nobody can buy is a
                // promise the page does not keep.
                'findCount' => $persona->picks
                    ->filter(fn ($pick) => $pick->group !== null && $pick->group->in_stock)
                    ->count(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function coves(CurrentMarket $current): array
    {
        /*
         * One band, every shape a Cove takes.
         *
         * It listed the six newest articles, which was the whole archive when
         * it was written. By 2026-09-08 a market had personas, brand and shop
         * Coves too, and a day that published fourteen advice pieces made the
         * band read as an advice column: the owner looked for the personas
         * under "Coves" and found none. The /coves page it links to groups by
         * kind for exactly that reason, and this band is its front window.
         *
         * Round-robin across the kinds, newest first within each, so a market
         * with all four shows all four and a market with one shows one. Until
         * 2026-09-08 the three personas a band above carried were skipped
         * here; that band is gone, so this is where a persona meets a
         * first-time visitor now.
         */
        $market = $current->get();

        $lanes = [
            DailyPickSet::query()->forMarket($market)->personas()->published(),
            DailyPickSet::query()->forMarket($market)->articles()->published(),
            DailyPickSet::query()->forMarket($market)->where('kind', CoveKind::Brand->value)->published(),
            DailyPickSet::query()->forMarket($market)->shops()->published(),
        ];

        $columns = ['id', 'kind', 'slug', 'theme_title', 'theme_blurb', 'source_volume'];
        $lanes = array_map(
            fn ($q) => $q->orderByDesc('published_at')->limit(6)->get($columns)->all(),
            $lanes,
        );

        $picked = [];
        while (count($picked) < 6 && array_filter($lanes)) {
            foreach ($lanes as &$lane) {
                if ($lane !== [] && count($picked) < 6) {
                    $picked[] = array_shift($lane);
                }
            }
            unset($lane);
        }

        return array_map(fn (DailyPickSet $cove) => [
            'title' => $cove->theme_title,
            'intro' => $cove->theme_blurb,
            'url' => $current->url($cove->kind->path((string) $cove->slug, $market)),
            // Named on the card, because a persona beside an advice piece
            // beside a brand reads as three unrelated things without it.
            'kind' => $cove->kind->value,
            // Why it exists, and a fact no competitor has.
            'searches' => $cove->source_volume,
        ], $picked);
    }
}
