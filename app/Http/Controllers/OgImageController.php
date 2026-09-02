<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BrandStat;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Seo\OgImage;
use App\Support\CurrentMarket;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;

/**
 * Renders the card a shared link turns into.
 *
 * One endpoint per kind of page, each taking an id or a slug and reading its own
 * text out of the database. **Nothing here accepts text from the request.** An
 * endpoint that draws arbitrary words onto a GiftCoves-branded card is an
 * impersonation tool with a URL, and our own domain would be serving the
 * screenshot.
 *
 * ## Cached hard — except for products, which are never cached
 *
 * A card costs real CPU — GD lays out and rasterises type at 1200×630, measured
 * at 58ms and 62KB per card. It is also completely stable between changes to the
 * row behind it, and the only clients are scrapers.
 *
 * That argues for caching, and it is right for every card *except* the product
 * one. Measured on production 2026-09-02: **113,626 product cards held 6.21GB of
 * Redis** on an 11.7GB box, against a live keyspace of about 2MB. That exhausted
 * memory, drove the machine into swap, and took the load average to 360 — the
 * Coolify API started answering 504 and no deploy could run.
 *
 * The volume is the obvious half. The decisive half is **hit rate**, and it runs
 * against intuition: a product card is fetched by a platform once and then held
 * by that platform for a week under the `max-age` below. So the entry is written
 * once and read almost never — 62KB kept for a month to save a single 58ms
 * render that will most likely never be asked for again. There are 293,770
 * product groups and something walks all of them.
 *
 * The cards that stay cached are the ones that get *re-read*: 43 Coves and
 * guides, 3,351 brands, one default per market — bounded by editorial output,
 * fetched repeatedly by many platforms over their life. Search result pages need
 * no rule here; they fall back to the default card.
 *
 * So: cache what gets re-read. Redis also carries `maxmemory 1gb` with
 * `volatile-lru` as a backstop, set the same day, so no future long tail can
 * repeat this.
 *
 * The cached bytes are keyed on two things: **the exact text the card will
 * draw**, and **the commit that rendered it**.
 *
 * The commit half is not belt and braces. A card's content comes from the row
 * *and* from the code and language files that lay it out, and only the first of
 * those moves `updated_at`. Caught in the act: the Daily Cove card first
 * rendered during a container swap, picked up a missing translation key, and
 * cached `SITE.OG.DAILY` in 24pt amber for thirty days — with no way to clear it
 * short of shell access to the box. Keying on the commit costs one re-render per
 * card per deploy, which nothing but a scraper will ever notice, and it makes a
 * bad card impossible to inherit across a deploy.
 *
 * The other half used to be the record's `updated_at`. See {@see self::fingerprint()}
 * for the two ways that was wrong and why the drawn text replaced it.
 *
 * The response carries a long `max-age` for the platforms that respect it and an
 * ETag for the ones that revalidate instead.
 */
class OgImageController extends Controller
{
    /** A month. The key changes when the content does, so this can be long. */
    private const TTL = 60 * 60 * 24 * 30;

    public function default(CurrentMarket $current, OgImage $og): Response
    {
        $language = $current->get()->language();

        return $this->card(
            'default:'.$current->value(),
            $og,
            __('site.og.default_title', [], $language),
            null,
            __('site.og.default_footnote', [], $language),
        );
    }

    public function product(CurrentMarket $current, OgImage $og, string $market, int $group): Response
    {
        $product = ProductGroup::query()
            ->forMarket($current->get())
            ->findOrFail($group);

        $language = $current->get()->language();

        // Rendered every time, deliberately. See the class docblock: this is the
        // one card whose cache entry was written far more often than it was read.
        return $this->render(
            $og,
            $product->title,
            __('site.og.product', [], $language),
            $this->offerLine($product, $language),
        );
    }

    public function guide(CurrentMarket $current, OgImage $og, string $market, string $slug): Response
    {
        $guide = DailyPickSet::query()
            ->forMarket($current->get())
            ->articles()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $language = $current->get()->language();

        return $this->card(
            'guide:'.$guide->id,
            $og,
            $guide->theme_title,
            __('site.og.guide', [], $language),
            __('site.og.guide_footnote', ['count' => $guide->picks()->count()], $language),
        );
    }

    /**
     * The Daily Cove, addressed by date rather than by "today".
     *
     * A platform caches the card it fetched when the link was first posted, and
     * `/daily` is a different edition every morning. Keying the image on the
     * date means yesterday's shared post keeps showing yesterday's theme instead
     * of quietly becoming today's.
     *
     * **Published editions only.** The page already refuses a future date,
     * because guessing tomorrow's puzzle by URL would be an obvious hole in a
     * daily game — and a card is a URL that renders the theme in 60pt type. An
     * image endpoint that skips a page's access rules is the page's access rules
     * with an extension on the end.
     */
    public function daily(CurrentMarket $current, OgImage $og, string $market, string $date): Response
    {
        $edition = DailyPickSet::query()
            ->forMarket($current->get())
            ->daily()
            ->published()
            ->whereDate('drop_date', $date)
            ->where('drop_date', '<=', now()->toDateString())
            ->firstOrFail();

        $language = $current->get()->language();

        return $this->card(
            'daily:'.$edition->id,
            $og,
            $edition->theme_title,
            __('site.og.daily', [], $language),
            $edition->drop_date->translatedFormat('j F Y'),
        );
    }

    public function brand(CurrentMarket $current, OgImage $og, string $market, string $slug): Response
    {
        $brand = BrandStat::query()
            ->forMarket($current->get())
            ->where('slug', $slug)
            ->firstOrFail();

        $language = $current->get()->language();

        return $this->card(
            'brand:'.$brand->id,
            $og,
            $brand->brand,
            __('site.og.brand', [], $language),
            __('site.og.brand_footnote', [
                'products' => Number::format($brand->product_count, locale: $language),
                'shops' => $brand->merchant_count,
            ], $language),
        );
    }

    /**
     * "14 shops · from € 279,00", or as much of it as is true.
     *
     * A product carried by one shop is not "1 shops", and one with no price is
     * not "from €0". Both cases are common enough in a feed that a card built
     * from the happy path would be visibly wrong in public.
     */
    private function offerLine(ProductGroup $product, string $language): ?string
    {
        $parts = [];

        if ($product->merchant_count > 0) {
            $parts[] = trans_choice('site.og.shops', $product->merchant_count, ['count' => $product->merchant_count], $language);
        }

        if ($product->min_price !== null && $product->min_price > 0) {
            $parts[] = __('site.og.from_price', [
                'price' => Number::currency($product->min_price / 100, 'EUR', $language),
            ], $language);
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @param  string  $scope  which record this is, so two of them never share an entry
     */
    private function card(string $scope, OgImage $og, string $title, ?string $kicker = null, ?string $footnote = null): Response
    {
        $png = Cache::remember(
            // `?? 'dev'` because the config is null off a deployment. An empty
            // segment would still work as a key, but every laptop and every
            // deployment that lost its SHA would then share one — and sharing a
            // cache key across builds is the exact failure this segment exists
            // to prevent.
            'og:'.(config('giftcoves.commit_sha') ?? 'dev').':'.$scope.':'.self::fingerprint($title, $kicker, $footnote),
            self::TTL,
            fn (): string => $og->render($title, $kicker, $footnote),
        );

        return $this->respond($png);
    }

    /**
     * The same card, drawn fresh and never stored. Used only by {@see self::product()}.
     *
     * Takes no `$scope`, because it has no key — and that is the point rather
     * than an omission. A caller that wanted to cache this would have to go
     * through {@see self::card()} and say which record it is for.
     */
    private function render(OgImage $og, string $title, ?string $kicker = null, ?string $footnote = null): Response
    {
        return $this->respond($og->render($title, $kicker, $footnote));
    }

    /**
     * Identical headers either way.
     *
     * A product card is not cached *here*, which is a fact about our memory and
     * nothing the platforms need to know: they still hold their copy for a week,
     * and that week is what keeps the render count survivable now that every
     * request draws. Weakening these headers for the uncached card would turn one
     * render per platform per week into one render per fetch.
     */
    private function respond(string $png): Response
    {
        return response($png, 200, [
            'Content-Type' => 'image/png',
            // A week at the platforms, and the key already changes with the
            // content, so a stale card cannot outlive an edit by much.
            'Cache-Control' => 'public, max-age=604800',
            'ETag' => '"'.md5($png).'"',
        ]);
    }

    /**
     * The exact text the card will draw, hashed.
     *
     * This was `updated_at`, which was the obvious choice and wrong twice over.
     *
     * **It is too coarse.** Laravel's `timestamps()` is `timestamp(0)` in
     * Postgres — whole seconds, verified on the column, not assumed. Two edits
     * inside one second are indistinguishable, so the second one kept serving
     * the first one's card for the full month.
     *
     * **It is also too narrow.** Half of what a card draws is not on the record
     * at all: `merchant_count` and `min_price` are aggregates, and a guide's
     * footnote counts its items. Ingestion writes those without touching the
     * parent row, so a product that went from five shops to fourteen went on
     * announcing five.
     *
     * Hashing the drawn strings is exact in both directions — the key moves when
     * the card would look different, and never otherwise. An edit that changes
     * only a description correctly re-serves the cached bytes.
     */
    private static function fingerprint(?string ...$parts): string
    {
        // NUL-separated because it cannot occur in any of these strings, so
        // ['ab', 'c'] and ['a', 'bc'] cannot collide onto one key. Truncated to
        // 64 bits, which is far more than enough inside a single record's scope.
        return substr(hash('sha256', implode("\0", array_map(
            static fn (?string $part): string => $part ?? '',
            $parts,
        ))), 0, 16);
    }
}
