<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ScoreSerendipity;
use App\Models\Event;
use App\Models\ProductGroup;
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

        return $groups->map(fn (ProductGroup $group) => [
            'id' => $group->id,
            'title' => $group->title,
            'brand' => $group->brand,
            'image' => $group->image_url,
            'price' => $group->min_price,
            'merchantCount' => $group->merchant_count,
            'url' => $current->url("p/{$group->id}/{$group->slug}"),
            // The strongest contributing signal, so the card can say *why* this
            // is unusual rather than merely asserting that it is.
            'why' => $this->why($group),
        ])->all();
    }

    /**
     * The single loudest reason, as a translation key.
     *
     * "Only one shop sells it" is a claim a reader can check; "surprising!" is
     * a claim they have to take on faith, and they will not.
     */
    private function why(ProductGroup $group): string
    {
        $breakdown = (array) ($group->surprise_breakdown ?? []);
        unset($breakdown['quality'], $breakdown['gated']);

        if ($breakdown === []) {
            return 'lexical';
        }

        arsort($breakdown);

        return (string) array_key_first($breakdown);
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
