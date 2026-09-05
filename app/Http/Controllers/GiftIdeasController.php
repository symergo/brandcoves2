<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPickSet;
use App\Services\Cove\CoveRail;
use App\Services\Cove\EditionPresenter;
use App\Services\Seo\PageMeta;
use App\Services\Seo\StructuredData;
use App\Support\CurrentMarket;
use App\Support\PreviewAccess;
use Illuminate\Http\Request;
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
        $personas = DailyPickSet::query()
            ->forMarket($current->get())
            ->personas()
            ->published()
            ->with(['picks.group'])
            // Newest first. A persona has no date to sort on, and `published_at`
            // is stamped once at first build and never refreshed by a rebuild,
            // so this is a stable shelf rather than one that reshuffles itself
            // every time the products are refreshed.
            ->orderByDesc('published_at')
            ->limit(60)
            ->get();

        app(PageMeta::class)->set(
            title: __('site.gift_ideas.title'),
            description: __('site.gift_ideas.description'),
            canonical: url($current->url('gift-ideas')),
        );

        return Inertia::render('GiftIdeas/Index', [
            'personas' => $personas->map(fn (DailyPickSet $set) => [
                'slug' => $set->slug,
                'title' => $set->theme_title,
                'blurb' => $set->theme_blurb,
                'url' => $current->url('gift-ideas/'.$set->slug),
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
                'findCount' => $set->picks
                    ->filter(fn ($pick) => $pick->group !== null && $pick->group->in_stock)
                    ->count(),
            ])->values()->all(),
        ]);
    }

    /** One persona. */
    public function show(Request $request, CurrentMarket $current, string $market, string $slug): Response
    {
        $preview = PreviewAccess::allowed($request);

        $persona = DailyPickSet::query()
            ->forMarket($current->get())
            ->personas()
            ->where('slug', $slug)
            ->unless($preview, fn ($q) => $q->published())
            ->with(['picks.group'])
            ->first();

        if ($persona === null) {
            throw new NotFoundHttpException;
        }

        $this->seo($persona, $current);

        return Inertia::render('GiftIdeas/Persona', [
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

    private function seo(DailyPickSet $persona, CurrentMarket $current): void
    {
        $url = url($current->url('gift-ideas/'.$persona->slug));

        app(PageMeta::class)->set(
            title: $persona->theme_title,
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
