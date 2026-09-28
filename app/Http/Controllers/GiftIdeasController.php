<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CoveKind;
use App\Models\DailyPickSet;
use App\Services\Cove\CoveRail;
use App\Services\Cove\EditionPresenter;
use App\Services\Cove\SavedCoves;
use App\Services\Gift\PersonaBudgets;
use App\Services\Gift\PersonaTopTen;
use App\Services\Seo\PageMeta;
use App\Services\Seo\StructuredData;
use App\Support\CurrentMarket;
use App\Support\PreviewAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gift personas: the Coves that are about a person rather than a day.
 *
 * "The cottagecore herbalist", "the dad who has everything". Built by the same
 * builder as a Daily Cove, from a plan curated on the same screen, and
 * presented by the same {@see EditionPresenter} — the difference is that a
 * persona has no date, so it never stops being current and it is addressed by a
 * permanent slug.
 *
 * That is why they earn a page of their own rather than a slot in the archive.
 * The daily column is a stream you catch up with; personas are a shelf you
 * browse, and "who am I shopping for" is the question a visitor arrives with.
 *
 * ## Why `/gift-ideas` and not `/coves/{slug}`
 *
 * `/coves/subscribe`, `/coves/confirm/{token}` and `/coves/unsubscribe/{token}`
 * already live under that prefix, and a `{slug}` catch-all beside them would
 * shadow all three the first time somebody named a persona "subscribe".
 */
class GiftIdeasController extends Controller
{
    public function __construct(
        private readonly EditionPresenter $presenter,
        private readonly CoveRail $rail,
    ) {}

    /** The shelf. */
    public function index(CurrentMarket $current, string $market): Response
    {
        $personas = $this->shelf(CoveKind::Persona, $current)
            // Newest first. A persona has no date to sort on, and `published_at`
            // is stamped once at first build and never refreshed by a rebuild,
            // so this is a stable shelf rather than one that reshuffles itself
            // every time the products are refreshed.
            ->orderByDesc('published_at')
            ->limit(60)
            ->get();

        /*
         * Gifts per occasion, a row of their own (owner, 2026-09-28): an
         * occasion is not a kind of person. In the order they were published,
         * which is the editorial order: there is no date to sort them on (see
         * CoveKind::Occasion).
         */
        $occasions = $this->shelf(CoveKind::Occasion, $current)
            ->orderBy('published_at')
            ->orderBy('id')
            ->limit(30)
            ->get();

        app(PageMeta::class)->set(
            title: __('site.gift_ideas.title'),
            description: __('site.gift_ideas.description'),
            canonical: url($current->url('gift-ideas')),
        );

        return Inertia::render('GiftIdeas/Index', [
            /*
             * No row of "gift ideas for dad" links here any more (owner,
             * 2026-09-28): the shelf is for personas. The recipient pages stay
             * reachable from Discover ("Of per persoon") and the sitemap.
             */
            'personas' => $personas->map(fn (DailyPickSet $set) => $this->card($set, $current))->values()->all(),
            'occasions' => $occasions->map(fn (DailyPickSet $set) => $this->card($set, $current))->values()->all(),
        ]);
    }

    /**
     * One kind's published Coves, with what a card shows.
     *
     * @return Builder<DailyPickSet>
     */
    private function shelf(CoveKind $kind, CurrentMarket $current)
    {
        return DailyPickSet::query()
            ->forMarket($current->get())
            ->where('kind', $kind->value)
            ->published()
            // Only what a card shows. Before `withCount()`, which adds to the
            // column list rather than replacing it; a `get([...])` after it
            // would be ignored.
            ->select(['id', 'kind', 'slug', 'theme_title', 'theme_blurb', 'scene', 'published_at'])
            /*
             * The in-stock finds counted by the database. Every pick and its
             * product used to be loaded, for one number per card: sixty
             * personas of a dozen picks each is seven hundred rows and two
             * queries, where this is one query and a number per persona.
             * A pick without a catalogue product (an Amazon decision) has no
             * group row, so `whereHas` leaves it out, as the old filter did.
             */
            ->withCount(['picks as find_count' => fn ($q) => $q->whereHas('group', fn ($g) => $g->where('in_stock', true))]);
    }

    /** @return array<string, mixed> */
    private function card(DailyPickSet $set, CurrentMarket $current): array
    {
        return [
            'slug' => $set->slug,
            'title' => $set->theme_title,
            'blurb' => $set->theme_blurb,
            'url' => $current->url($set->kind->path((string) $set->slug, $current->get())),
            /*
                 * The drawing, not a product photograph.
                 *
                 * The cover used to be the first buyable find, which made a
                 * shelf of *people* look like a shelf of products and changed
                 * the persona's face whenever its stock did — a page looking
                 * new for a reason no reader could see and no editor chose.
                 *
                 * Null until a curator picks one; the component reads that as
                 * `someone` and draws a figure. See App\Enums\CoveScene.
                 */
            'scene' => $set->scene?->value,
            'findCount' => (int) $set->find_count,
        ];
    }

    /** One persona. */
    public function show(Request $request, CurrentMarket $current, string $market, string $slug): Response
    {
        return $this->page($request, $current, CoveKind::Persona, $slug);
    }

    /** Gifts for one occasion: the same page, its own address (CoveKind::Occasion). */
    public function occasion(Request $request, CurrentMarket $current, string $market, string $slug): Response
    {
        return $this->page($request, $current, CoveKind::Occasion, $slug);
    }

    private function page(Request $request, CurrentMarket $current, CoveKind $kind, string $slug): Response
    {
        $preview = PreviewAccess::allowed($request);

        $persona = DailyPickSet::query()
            ->forMarket($current->get())
            ->where('kind', $kind->value)
            ->where('slug', $slug)
            ->unless($preview, fn ($q) => $q->published())
            // The footer guide and its count in the same round; see
            // DailyCoveController::findEdition().
            ->with(['picks.group', 'featured' => fn ($q) => $q->withCount('picks')])
            ->first();

        if ($persona === null) {
            throw new NotFoundHttpException;
        }

        $this->seo($persona, $current);

        // Around 15, 40 and 100, and ideas without a shop: under the curated
        // shelf, never instead of it. See docs/features/persona-budgets.md.
        $budgets = app(PersonaBudgets::class)->for($persona, $current);

        return Inertia::render('GiftIdeas/Persona', [
            'budgets' => $budgets['bands'],
            'offlineIdeas' => $budgets['ideas'],
            // This week's top 10, at the end of the page; null when the
            // persona cannot fill one. See docs/features/persona-top-ten.md.
            'topTen' => app(PersonaTopTen::class)->forPage($persona, $current),
            // Save into My Coves; see docs/features/saved-coves.md.
            'saveCove' => app(SavedCoves::class)->button($persona->id),
            'preview' => $preview && ! $persona->isPublished(),
            'persona' => [
                'id' => $persona->id,
                'slug' => $persona->slug,
                'title' => $persona->theme_title,
                'blurb' => $persona->theme_blurb,
                'scene' => $persona->scene?->value,
                'editorial' => $this->presenter->editorial($persona, $current),
            ],
            'finds' => $this->presenter->finds($persona, $current),
            'guide' => $this->presenter->guide($persona, $current),

            /*
             * The other personas, and more of what this one is about.
             *
             * A persona used to end in one link back to the shelf it came off,
             * which made it the narrowest dead end of the three Cove pages: a
             * reader who arrived here from search left with six products and
             * one link.
             */
            'rail' => $this->rail->for($persona, $current),
        ]);
    }

    /**
     * A persona's listing title, which its heading cannot be.
     *
     * "The one who reads" and "De wandelaar" are good headings and unsearchable
     * listings: they contain no word anybody types into a search box. The query
     * is "gift for someone who reads", and this page is exactly that answer — so
     * the <h1> keeps the editorial title and the listing says what the page is
     * for.
     *
     * Falls back to the bare title when the two together run past the 48
     * characters a listing leaves after " · GiftCoves". That is not a rare edge:
     * "The one who is always going somewhere" needs it, and it is a real
     * published persona.
     */
    private function listingTitle(DailyPickSet $persona): string
    {
        // An occasion's title ("Cadeaus voor Moederdag") is already what
        // people search for; the persona template would say it twice.
        if ($persona->kind === CoveKind::Occasion) {
            return $persona->theme_title;
        }

        $titled = __('site.gift_ideas.persona_seo_title', [
            // Lowercased so the article reads as part of the sentence the
            // template makes: "Cadeau voor de wandelaar", not "voor De".
            'persona' => Str::lcfirst($persona->theme_title),
        ]);

        return mb_strlen($titled) <= 48 ? $titled : $persona->theme_title;
    }

    private function seo(DailyPickSet $persona, CurrentMarket $current): void
    {
        $url = url($current->url($persona->kind->path((string) $persona->slug, $current->get())));

        app(PageMeta::class)->set(
            title: $this->listingTitle($persona),
            description: $persona->theme_blurb ?? __('site.gift_ideas.description'),
            canonical: $url,
        );

        app(PageMeta::class)->addJsonLd(StructuredData::breadcrumbs([
            ['name' => 'GiftCoves', 'url' => url($current->url())],
            ['name' => __('site.gift_ideas.title'), 'url' => url($current->url('gift-ideas'))],
            ['name' => $persona->theme_title, 'url' => $url],
        ]));
    }
}
