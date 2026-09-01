<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\BrandStat;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Guides\CoveMarkup;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * All Coves: every Cove this market has published, in one place.
 *
 * The three Cove surfaces each had an index of their own and there was nowhere
 * that held all of them. `/daily` is today's edition with a short archive strip,
 * `/gift-ideas` is the persona shelf and `/guides` is the article archive — so a
 * reader who had understood that "Cove" is one thing with several shapes had no
 * page that showed them the shape of the whole thing. The header said Cove five
 * times and pointed at four different rooms.
 *
 * This is the overview, not a fourth index. Each section is capped and links to
 * the index that owns it: the value here is *range* — that there is a daily
 * column, a shelf of people and a stack of long reads — and range is legible
 * from a dozen titles. Sixty of each would be three archives stapled together,
 * and the reader would have to scroll past the whole daily column to discover
 * that personas exist.
 *
 * Sections rather than one reverse-chronological stream, for the same reason.
 * A market publishes an edition every morning and a persona every few weeks, so
 * a merged stream sorted by date is the daily column with occasional strangers
 * in it — the exact opposite of an overview.
 *
 * ## Why `/coves`
 *
 * `/coves/subscribe`, `/coves/confirm/{token}` and `/coves/unsubscribe/{token}`
 * already live under this prefix. They are safe neighbours because this route is
 * the literal segment and not a `{slug}` catch-all — the catch-all is precisely
 * what `GiftIdeasController` documents avoiding, and adding one here later would
 * shadow all three the first time somebody named a Cove "subscribe".
 */
class CovesController extends Controller
{
    /**
     * How many of each kind the overview shows.
     *
     * Twelve matches `DiscoverCoveController::COVES`, and for the same reason
     * stated there: more than a taste, fewer than an archive. Editions are
     * capped harder because they arrive daily and would otherwise be the page.
     */
    private const PER_SECTION = 12;

    private const EDITIONS = 8;

    public function __invoke(CurrentMarket $current, ConnectorRegistry $registry): Response
    {
        app(PageMeta::class)->set(
            title: __('site.coves.seo_title'),
            description: __('site.coves.seo_description'),
            canonical: url($current->url('coves')),
        );

        return Inertia::render('Coves/Index', [
            /*
             * One section per Cove type, in the order the header lists them.
             *
             * Sent as a list rather than three named props so the page renders
             * whatever it is given: a market with no personas yet drops that
             * section instead of printing a heading over an empty grid, and
             * adding a fourth kind is a change here rather than in the layout.
             */
            'sections' => array_values(array_filter([
                $this->section(
                    'daily',
                    DailyPickSet::query()->daily()->orderByDesc('drop_date'),
                    self::EDITIONS,
                    $current,
                ),
                $this->section(
                    'gift',
                    DailyPickSet::query()->personas()->orderByDesc('published_at'),
                    self::PER_SECTION,
                    $current,
                ),
                $this->section(
                    'smart',
                    DailyPickSet::query()->articles()->orderByDesc('published_at'),
                    self::PER_SECTION,
                    $current,
                ),
                $this->brands($current),
                // The writing if there is any, the directory of shops if not.
                $this->shopCoves($current) ?? $this->shops($current, $registry),
            ])),
        ]);
    }

    /**
     * Brand Coves: the makers with a page of their own here.
     *
     * `brand_stats` rather than a `distinct brand` over the catalogue, for the
     * reason that table exists: a brand's identity is its **slug**, feeds
     * disagree about punctuation, and "Audio-Technica" and "Audio Technica" are
     * one brand with two spellings. Reading the raw column would list it twice
     * and link one of them to a page with half the products.
     *
     * `pageworthy()` is the same gate `/brands` applies. A brand behind one
     * out-of-stock offer has a page that is technically valid and worth nobody's
     * click, and this band must not be the place it gets promoted.
     *
     * @return array<string, mixed>|null
     */
    private function brands(CurrentMarket $current): ?array
    {
        $brands = BrandStat::query()
            ->forMarket($current->get())
            ->pageworthy()
            ->orderByDesc('product_count')
            ->limit(self::PER_SECTION)
            ->get(['brand', 'slug']);

        if ($brands->isEmpty()) {
            return null;
        }

        return [
            'key' => 'brand',
            'url' => $current->url('brands'),
            'coves' => $brands->map(fn (BrandStat $stat): array => [
                'title' => $stat->brand,
                // No blurb and no count. There is nothing written about a brand
                // that is not on its own page, and a number here would be the
                // catalogue-counter mistake this page already refuses.
                'intro' => null,
                'url' => $current->url("brand/{$stat->slug}"),
                'date' => null,
            ])->all(),
        ];
    }

    /**
     * Shop Coves: the shops this market's prices are compared across.
     *
     * Membership is "has active offers here, or is a live source serving this
     * market" — the same question `/shops` asks, and the reasoning for asking
     * it of the catalogue rather than of `feeds` is written out there.
     *
     * @return array<string, mixed>|null
     */
    private function shops(CurrentMarket $current, ConnectorRegistry $registry): ?array
    {
        $market = $current->get();
        $live = $registry->liveSourcesFor($market);

        $shops = Merchant::query()
            ->where('enabled', true)
            ->where(function (Builder $q) use ($market, $live): void {
                $q->whereHas('products', fn (Builder $p) => $p
                    ->where('market', $market->value)
                    ->where('status', ProductStatus::Active->value));

                if ($live !== []) {
                    $q->orWhereIn('source', array_map(fn (Source $s) => $s->value, $live));
                }
            })
            // Newest first here, unlike the A–Z on `/shops` itself. This band is
            // a dozen of them on an overview page: which shops are *new* is the
            // only ordering that says something a full alphabetical list does
            // not already say better.
            ->orderByDesc('created_at')
            ->limit(self::PER_SECTION)
            ->get(['id', 'name', 'domain']);

        if ($shops->isEmpty()) {
            return null;
        }

        return [
            'key' => 'shop',
            'url' => $current->url('shops'),
            'coves' => $shops->map(fn (Merchant $shop): array => [
                'title' => $shop->name,
                'intro' => $shop->domain,
                'url' => $current->url('search').'?merchant%5B%5D='.$shop->id,
                'date' => null,
            ])->all(),
        ];
    }

    /**
     * The writing about shops, when there is any.
     *
     * Preferred over the directory band above: a Cove is something somebody
     * wrote, and an overview of *all Coves* should show the writing before it
     * shows a list of company names. {@see shops()} is the fallback for a market
     * where none has been written yet — which is every market today, and would
     * otherwise leave this page silent about a whole section of the header.
     *
     * @return array<string, mixed>|null
     */
    private function shopCoves(CurrentMarket $current): ?array
    {
        $coves = DailyPickSet::query()
            ->forMarket($current->get())
            ->shops()
            ->published()
            ->orderByDesc('published_at')
            ->limit(self::PER_SECTION)
            ->get(['id', 'kind', 'slug', 'theme_title', 'theme_blurb']);

        if ($coves->isEmpty()) {
            return null;
        }

        return [
            'key' => 'shop',
            'url' => $current->url('shops'),
            'coves' => $coves->map(fn (DailyPickSet $cove): array => [
                'title' => $cove->theme_title,
                'intro' => app(CoveMarkup::class)->plain($cove->theme_blurb),
                'url' => $current->url($cove->kind->path((string) $cove->slug)),
                'date' => null,
            ])->all(),
        ];
    }

    /**
     * One band: a kind, up to `$limit` of it, and where the rest of it lives.
     *
     * Null when the market has published none of that kind. An empty shelf is
     * worse than no shelf — the same rule `DiscoverCoveController` applies to
     * today's edition.
     *
     * @param  Builder<DailyPickSet>  $query
     * @return array<string, mixed>|null
     */
    private function section(string $key, $query, int $limit, CurrentMarket $current): ?array
    {
        /** @var Collection<int, DailyPickSet> $coves */
        $coves = $query
            ->forMarket($current->get())
            ->published()
            ->limit($limit)
            ->get(['id', 'kind', 'slug', 'drop_date', 'theme_title', 'theme_blurb']);

        if ($coves->isEmpty()) {
            return null;
        }

        return [
            'key' => $key,
            /*
             * Where the whole of this kind lives.
             *
             * Daily points at `/daily`, which is today's edition rather than an
             * index — that is what "all the editions" means here, because the
             * archive strip lives on it and every past edition keeps its own
             * URL. Naming a route that does not exist would be worse than
             * pointing at the page that actually holds them.
             */
            'url' => $current->url(match ($key) {
                'daily' => 'daily',
                'gift' => 'gift-ideas',
                default => 'guides',
            }),
            'coves' => $coves->map(fn (DailyPickSet $cove): array => [
                'title' => $cove->theme_title,
                // Tokens flattened to their labels, exactly as `/guides` and the
                // Discover hub do it: a link inside a card whose whole surface
                // is already a link is a target fighting its parent.
                'intro' => app(CoveMarkup::class)->plain($cove->theme_blurb),
                /*
                 * By slug, editions included. `CoveKind::path()` takes
                 * "whatever addresses it", and for a Daily that is the slug:
                 * `/daily/{date}` exists but 301s onto `/daily/{slug}`, so
                 * linking by date would send every click on this page through a
                 * redirect. The archive strip on `/daily` already links this
                 * way.
                 */
                'url' => $current->url($cove->kind->path((string) $cove->slug)),
                /*
                 * Null on everything but an edition, and the card simply omits
                 * it. A persona has no date on purpose — it never stops being
                 * current — so printing its publication date would invite the
                 * reader to treat an old one as stale.
                 *
                 * Formatted here, as `DailyCoveController`'s archive strip does
                 * it. With the year, because this list reaches back further than
                 * that strip does and "3 Feb" alone is ambiguous across one.
                 */
                'date' => $cove->drop_date?->format('j M Y'),
            ])->all(),
        ];
    }
}
