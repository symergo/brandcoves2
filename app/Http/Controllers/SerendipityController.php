<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Source;
use App\Jobs\ScoreSerendipity;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Catalogue\Excerpt;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Show me something I didn't know existed."
 *
 * The Serendipity Engine's own surface, separate from Daily Picks: the daily
 * set is an appointment, curated and themed, and this is the button you press
 * when you want another one right now. Same scores underneath.
 *
 * Reads `surprise_score`, which {@see ScoreSerendipity} computed after
 * the last ingest. Nothing is scored in the request — the scoring needs the
 * whole catalogue's word distribution, which is a job's worth of work.
 */
class SerendipityController extends Controller
{
    /**
     * How deep into the ranking a reroll can reach.
     *
     * Sampling from the top slice rather than always returning the top N: a
     * surface whose entire purpose is surprise cannot show the same six things
     * to everyone forever. Wide enough to feel inexhaustible, narrow enough
     * that everything in it genuinely scored well.
     */
    private const POOL = 200;

    public function __invoke(Request $request, CurrentMarket $current): Response
    {
        $this->seo($current);

        $exclude = array_slice(
            array_map('intval', (array) $request->input('seen', [])),
            // Bounded so a hand-built request cannot turn the exclusion list
            // into an unbounded IN clause.
            -60,
        );

        $finds = $this->sample($current, $exclude);

        Event::record('serendipity.view', [
            'market' => $current->value(),
            'results' => count($finds),
        ]);

        return Inertia::render('Surprise', [
            'finds' => $finds,
            'seen' => array_values(array_unique([...$exclude, ...array_column($finds, 'id')])),
        ]);
    }

    /**
     * @param  list<int>  $exclude
     * @return list<array<string, mixed>>
     */
    private function sample(CurrentMarket $current, array $exclude): array
    {
        /*
         * Take the top slice by score, then shuffle inside it.
         *
         * `ORDER BY random()` over the whole table would be both slow and
         * wrong — it would return median products. Ranking first and
         * randomising second means everything shown genuinely earned its place,
         * while a surface whose entire purpose is surprise does not show the
         * same six things to everyone forever.
         */
        $pool = ProductGroup::query()
            ->forMarket($current->get())
            ->presentable()
            ->where('surprise_score', '>', 0)
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('id', $exclude))
            ->orderByDesc('surprise_score')
            ->limit(self::POOL)
            ->pluck('id');

        if ($pool->isEmpty()) {
            return [];
        }

        $ids = $pool->shuffle()->take(6);

        $groups = ProductGroup::query()->whereIn('id', $ids)->get();
        $blurbs = $this->blurbs($ids->all());

        return $groups->map(fn (ProductGroup $group) => [
            'id' => $group->id,
            'title' => $group->title,
            'brand' => $group->brand,
            'image' => $group->image_url,
            'price' => $group->min_price,
            'merchantCount' => $group->merchant_count,
            'url' => $current->url("p/{$group->id}/{$group->slug}"),
            // What the thing IS. Null when no offer carries a usable
            // description, which the card handles rather than papering over.
            'blurb' => $blurbs[$group->id] ?? null,
        ])->all();
    }

    /**
     * One line of description per group, from the offers beneath it.
     *
     * The card used to carry the scoring reason instead — "a corner of the
     * catalogue nobody browses", "a brand you probably have not heard of".
     * Read six at a time down a grid, every one of those is a sentence about
     * *our ranking*, and none of them says what the object on the card is. A
     * visitor looking at an unfamiliar product does not need to be told it is
     * unfamiliar; that is the one thing they already know.
     *
     * The description lives on `products` (the offer), not on the group, so it
     * is fetched for all six at once — six lazy `bestOffer` loads is the N+1
     * this page would otherwise ship.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function blurbs(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $storable = array_values(array_filter(
            Source::cases(),
            fn (Source $source) => $source->allowsCatalogueStorage(),
        ));

        return Product::query()
            ->whereIn('group_id', $ids)
            // Invariant 6. An Amazon-sourced row may not have its copy
            // reproduced from our own store, and this is exactly the kind of
            // convenience read that would quietly do it.
            ->whereIn('source', array_column($storable, 'value'))
            ->whereNotNull('description')
            /*
             * Longest first, and take the first per group.
             *
             * Merchants selling the same product supply wildly uneven copy —
             * one gives a paragraph, the next gives "Zwart". Length is a crude
             * proxy for informativeness, but on this field it is a reliable
             * one, and it beats the arbitrary order a plain `whereIn` returns.
             */
            ->orderByRaw('length(description) desc')
            ->get(['group_id', 'description'])
            ->groupBy('group_id')
            ->map(fn ($offers) => $offers
                ->map(fn (Product $offer) => Excerpt::make($offer->description))
                ->first(fn (?string $blurb) => $blurb !== null))
            ->filter()
            ->all();
    }

    private function seo(CurrentMarket $current): void
    {
        app(PageMeta::class)->set(
            title: __('site.surprise.title'),
            description: __('site.surprise.seo_description'),
            canonical: url($current->url('surprise')),
            // The contents change on every visit by design. A crawler indexing
            // one random draw as the canonical version of this page is worse
            // than it indexing the pitch and following the product links.
            robots: 'index, follow',
        );
    }
}
