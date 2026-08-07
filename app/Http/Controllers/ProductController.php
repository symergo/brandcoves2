<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Seo\PageMeta;
use App\Services\Seo\StructuredData;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            'history' => $this->priceHistory($productGroup),
        ]);
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
                // Never the raw affiliate URL: the click goes through our own
                // redirector so it can be scheme-checked and logged.
                'url' => route('go', ['market' => $offer->market->value, 'offer' => $offer->id]),
            ])
            ->values()
            ->all();
    }

    /**
     * Daily low across all offers, for the sparkline.
     *
     * The minimum rather than any single shop's price: the line answers "what
     * would this have cost me", which is the question a price chart is for.
     *
     * @return list<array{date: string, price: int}>
     */
    private function priceHistory(ProductGroup $group): array
    {
        return DB::table('price_history as h')
            ->join('products as p', 'p.id', '=', 'h.product_id')
            ->where('p.group_id', $group->id)
            ->where('h.captured_on', '>=', now()->subDays(90)->toDateString())
            ->groupBy('h.captured_on')
            ->orderBy('h.captured_on')
            ->select('h.captured_on as date', DB::raw('min(h.price) as price'))
            ->get()
            ->map(fn ($r) => ['date' => (string) $r->date, 'price' => (int) $r->price])
            ->all();
    }
}
