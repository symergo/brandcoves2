<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CoveKind;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Services\Guides\CoveMarkup;
use App\Services\Seo\PageMeta;
use App\Services\Wishlist\WizardOffer;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Rows in the Coves shelf. Ten is a band; more is a page, and the archive
     * is one link away.
     */
    private const COVES_SHOWN = 10;

    /** How long one draw of the shelf is kept, per market. Seconds. */
    private const COVES_TTL = 3600;

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

    /** @return list<array<string, mixed>> */
    /**
     * A handful of other Coves, for whoever has read today's.
     *
     * Ten published Coves of any kind but the dailies, drawn at random and
     * held for an hour per market. It was "recent, newest first" for an
     * afternoon on 2026-09-13; the owner asked for a random pick instead,
     * and asked that the page not say so — a heading like "More Coves" is
     * an invitation, "random" is an admission. Dailies stay out: one appears
     * every day, so any list of ten would be a week of editions with the
     * writing pushed off the end, and they have Today's Cove above and their
     * own archive. No dates on the rows either, since a date on a row implies
     * an order the list does not have.
     *
     * Cached rather than drawn per request so that a visitor who reloads or
     * comes back the same morning sees the same shelf, and so the front page
     * does not pay for `ORDER BY random()` on every hit. An hour is short
     * enough that the shelf changes between visits on different days, which
     * is the point of drawing at random at all. The cache carries the
     * presented rows, URLs included, so the key is per market.
     *
     * Blurbs are flattened to their labels the way the archive does it: a
     * link inside a row that is already a link is a target fighting its
     * parent.
     *
     * @return list<array<string, mixed>>
     */
    private function coves(CurrentMarket $current): array
    {
        $market = $current->get();

        return Cache::remember(
            'home.coves:'.$market->value,
            self::COVES_TTL,
            fn (): array => DailyPickSet::query()
                ->forMarket($market)
                ->published()
                ->whereNot('kind', CoveKind::Daily->value)
                ->inRandomOrder()
                ->limit(self::COVES_SHOWN)
                ->get(['id', 'kind', 'slug', 'theme_title', 'theme_blurb'])
                ->map(fn (DailyPickSet $cove): array => [
                    'kind' => $cove->kind->value,
                    'title' => $cove->theme_title,
                    'intro' => app(CoveMarkup::class)->plain($cove->theme_blurb),
                    'url' => $current->url($cove->kind->path((string) $cove->slug, $market)),
                ])
                ->all(),
        );
    }
}
