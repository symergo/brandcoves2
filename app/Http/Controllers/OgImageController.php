<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BrandStat;
use App\Models\Guide;
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
 * endpoint that draws arbitrary words onto a Brandcoves-branded card is an
 * impersonation tool with a URL, and our own domain would be serving the
 * screenshot.
 *
 * ## Cached hard, on purpose
 *
 * A card costs real CPU — GD lays out and rasterises type at 1200×630. It is
 * also completely stable between changes to the row behind it, and the only
 * clients are scrapers.
 *
 * So the bytes are cached under a key that includes the record's `updated_at`: a
 * retitled product renders once more and never again, and no cache needs
 * clearing at deploy. The response carries a long `max-age` for the platforms
 * that respect it and an ETag for the ones that revalidate instead.
 */
class OgImageController extends Controller
{
    /** A month. The key changes when the content does, so this can be long. */
    private const TTL = 60 * 60 * 24 * 30;

    public function default(CurrentMarket $current, OgImage $og): Response
    {
        return $this->send(
            'default:'.$current->value(),
            fn () => $og->render(
                __('site.og.default_title', [], $current->get()->language()),
                null,
                __('site.og.default_footnote', [], $current->get()->language()),
            ),
        );
    }

    public function product(CurrentMarket $current, OgImage $og, string $market, int $group): Response
    {
        $product = ProductGroup::query()
            ->forMarket($current->get())
            ->findOrFail($group);

        $language = $current->get()->language();

        return $this->send(
            'product:'.$product->id.':'.$product->updated_at?->timestamp,
            fn () => $og->render(
                $product->title,
                __('site.og.product', [], $language),
                $this->offerLine($product, $language),
            ),
        );
    }

    public function guide(CurrentMarket $current, OgImage $og, string $market, string $slug): Response
    {
        $guide = Guide::query()
            ->forMarket($current->get())
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        return $this->send(
            'guide:'.$guide->id.':'.$guide->updated_at?->timestamp,
            fn () => $og->render(
                $guide->title,
                __('site.og.guide', [], $current->get()->language()),
                __('site.og.guide_footnote', ['count' => $guide->items()->count()], $current->get()->language()),
            ),
        );
    }

    public function brand(CurrentMarket $current, OgImage $og, string $market, string $slug): Response
    {
        $brand = BrandStat::query()
            ->forMarket($current->get())
            ->where('slug', $slug)
            ->firstOrFail();

        $language = $current->get()->language();

        return $this->send(
            'brand:'.$brand->id.':'.$brand->updated_at?->timestamp,
            fn () => $og->render(
                $brand->brand,
                __('site.og.brand', [], $language),
                __('site.og.brand_footnote', [
                    'products' => Number::format($brand->product_count, locale: $language),
                    'shops' => $brand->merchant_count,
                ], $language),
            ),
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

    /** @param callable(): string $render */
    private function send(string $key, callable $render): Response
    {
        $png = Cache::remember('og:'.$key, self::TTL, $render);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            // A week at the platforms, and the key already changes with the
            // content, so a stale card cannot outlive an edit by much.
            'Cache-Control' => 'public, max-age=604800',
            'ETag' => '"'.md5($png).'"',
        ]);
    }
}
