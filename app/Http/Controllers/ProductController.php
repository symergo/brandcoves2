<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Models\PriceAlert;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\RestockAlert;
use App\Services\Alerts\AlertEligibility;
use App\Services\Seo\PageMeta;
use App\Services\Seo\StructuredData;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductController extends Controller
{
    /**
     * The `{market}` route parameter is consumed by SetMarket middleware, but
     * the router still passes it positionally — so it has to be accepted here
     * even though CurrentMarket is what the method actually uses.
     */
    public function __invoke(CurrentMarket $current, string $market, string $group, ?string $slug = null): Response|RedirectResponse
    {
        $productGroup = ProductGroup::query()
            ->forMarket($current->get())
            ->find((int) $group);

        if ($productGroup === null) {
            throw new NotFoundHttpException;
        }

        // The slug is decoration; the id is identity. A stale slug from an old
        // link or a retitled product redirects rather than 404s, so shared and
        // indexed links keep working after an upstream title change.
        if ($slug !== $productGroup->slug) {
            return redirect()->to($current->url("p/{$productGroup->id}/{$productGroup->slug}"), 301);
        }

        $offers = $productGroup->offers()
            ->with('merchant')
            ->where('status', ProductStatus::Active->value)
            ->orderByRaw("(availability = 'in_stock') DESC")
            ->orderByRaw('price ASC NULLS LAST')
            ->orderBy('id')
            ->get();

        $this->seo($productGroup, $offers->all(), $current);

        return Inertia::render('Product', [
            'product' => [
                'id' => $productGroup->id,
                'title' => $productGroup->title,
                'brand' => $productGroup->brand,
                'image' => $productGroup->image_url,
                'category' => $productGroup->category,
                'minPrice' => $productGroup->min_price,
                'maxPrice' => $productGroup->max_price,
                'medianPrice' => $productGroup->median_price,
                'discountPercent' => $productGroup->discountPercent(),
                'inStock' => $productGroup->in_stock,
                'merchantCount' => $productGroup->merchant_count,
                'identityKind' => $productGroup->identity_kind?->value,
                // Only shown when it is a real barcode — the title-fallback key
                // is an internal string and would mean nothing to a shopper.
                'ean' => $productGroup->identity_kind?->value === 'ean' ? $productGroup->identity_key : null,
            ],
            'offers' => $this->presentOffers($offers),
            'alert' => $this->alertState($productGroup),
        ]);
    }

    /**
     * Whether this product can carry an alert, and whether it already does.
     *
     * `excluded` is sent so the button can say which shops are *not* watched.
     * Silently narrowing what "alert me when it drops" means would be a lie the
     * shopper only discovers when a drop passes them by.
     *
     * @return array<string, mixed>
     */
    private function alertState(ProductGroup $group): array
    {
        $eligibility = app(AlertEligibility::class);
        $user = request()->user();

        return [
            'eligible' => $eligibility->isEligible($group),
            'excluded' => $eligibility->excludedSources($group),
            'requiresAccount' => $user === null,
            'price' => $user !== null && PriceAlert::query()
                ->where('group_id', $group->id)
                ->where('user_id', $user->id)
                ->exists(),
            'restock' => $user !== null && RestockAlert::query()
                ->where('group_id', $group->id)
                ->where('user_id', $user->id)
                ->exists(),
        ];
    }

    /**
     * Page metadata and structured data.
     *
     * The description leads with the price and the seller count because that is
     * both what we uniquely know and what earns the click from a listing.
     *
     * @param  list<Product>  $offers
     */
    private function seo(ProductGroup $group, array $offers, CurrentMarket $current): void
    {
        $market = $current->get();
        $url = url($current->url("p/{$group->id}/{$group->slug}"));

        $description = $group->min_price !== null && $group->merchant_count > 1
            ? __('site.product.seo_compare', [
                'title' => $group->title,
                'price' => $this->money($group->min_price, $market),
                'count' => $group->merchant_count,
            ])
            : __('site.product.seo_single', [
                'title' => $group->title,
                'price' => $group->min_price === null ? '' : $this->money($group->min_price, $market),
            ]);

        $meta = app(PageMeta::class);

        $meta->set(
            title: $group->title,
            description: $description,
            image: $group->image_url,
            canonical: $url,
            // A product nobody stocks is a thin page; keep it out of the index
            // but keep following its links.
            robots: $offers === [] ? 'noindex, follow' : null,
        );

        $meta->addJsonLd(StructuredData::product($group, $offers, $market, $url));
        $meta->addJsonLd(StructuredData::breadcrumbs([
            ['name' => 'Brandcoves', 'url' => url($current->url())],
            ['name' => __('site.search.title'), 'url' => url($current->url('search'))],
            ['name' => $group->title, 'url' => $url],
        ]));
    }

    private function money(int $cents, Market $market): string
    {
        return Number::currency($cents / 100, $market->currency(), $market->hrefLang());
    }

    /**
     * Every shop selling this product, cheapest first.
     *
     * This table is the product. Everything else on the page exists to give it
     * context.
     *
     * @param  Collection<int, Product>  $offers
     * @return list<array<string, mixed>>
     */
    private function presentOffers(Collection $offers): array
    {
        return $offers
            ->map(fn (Product $offer) => [
                'id' => $offer->id,
                'merchant' => $offer->merchant?->name ?? $offer->source->label(),
                'merchantLogo' => $offer->merchant?->faviconUrl(),
                'price' => $offer->price,
                'currency' => $offer->currency,
                'availability' => $offer->availability->value,
                'isBuyable' => $offer->availability->isBuyable(),
                'title' => $offer->title,
                // Our redirector for most sources; a direct anchor where the
                // programme requires an unobscured link (Amazon). Null when the
                // stored URL is unsafe, so the view renders no link at all.
                'url' => $offer->outboundUrl(),
                'direct' => $offer->source->requiresDirectLink(),
                // Direct links bypass the redirector, so the click is reported
                // by the browser instead.
                'beacon' => $offer->source->requiresDirectLink()
                    ? route('click.beacon', ['market' => $offer->market->value])
                    : null,
                // Amazon requires an "as of" note: the price may have moved
                // since it was fetched.
                'needsPriceTimestamp' => $offer->source->requiresPriceTimestamp(),
            ])
            ->filter(fn (array $offer) => $offer['url'] !== null)
            ->values()
            ->all();
    }

    /*
     * The 90-day price chart used to be built here.
     *
     * Removed from the product page on request. `price_history` itself stays —
     * it is what the 30-day median is computed from, and the median drives the
     * discount badge and the alert thresholds — so the table, the ingest write
     * and the pruning job are all unchanged. What is gone is the chart and the
     * per-page query behind it, which fetched ninety rows for every render of
     * the most-crawled page type on the site.
     *
     * The compliance rule the chart carried has not gone anywhere. Sources that
     * disallow price tracking are now filtered where the median is computed, in
     * `ProductGrouper::recomputeAggregates()` — one gate covering every reader of
     * the median instead of one covering the chart alone.
     * See docs/features/amazon-compliance.md.
     */
}
