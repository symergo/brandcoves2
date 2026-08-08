<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\ProductGroup;
use App\Services\Identity\Gtin;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Scan a barcode in a shop, find out whether it is cheaper elsewhere.
 *
 * The feature the catalogue was already shaped for: `product_groups` is unique
 * on `(market, identity_key)` and for an EAN-grouped product that key **is** the
 * GTIN. So a scan is one unique-index hit — no new table, no new index, no
 * matching logic. The expensive part was done in Phase 1 without knowing it.
 *
 * The interesting case is the miss. Someone standing in a shop holding the
 * product has told us it exists and that nobody in our catalogue sells it; that
 * is a supply gap worth recording, and worth answering with a text search
 * rather than a dead end.
 */
class ScanController extends Controller
{
    public function show(CurrentMarket $current): Response
    {
        app(PageMeta::class)->set(
            title: __('site.scan.title'),
            description: __('site.scan.seo_description'),
            canonical: url($current->url('scan')),
        );

        return Inertia::render('Scan');
    }

    /**
     * Resolve a scanned code.
     *
     * JSON rather than a redirect: the scanner keeps running while the answer
     * arrives, so a miss can be reported without tearing down the camera and
     * asking for permission again.
     */
    public function resolve(Request $request, CurrentMarket $current, string $market, string $barcode): JsonResponse
    {
        /*
         * Normalise before looking anything up.
         *
         * A camera reads a UPC-A as 12 digits and an outer carton as a 14-digit
         * ITF-14, while the catalogue stores GTIN-13. Gtin::normalise handles
         * both, plus the check digit — so a misread is rejected here rather
         * than becoming a confident "not found".
         */
        $gtin = Gtin::normalise($barcode);

        if ($gtin === null) {
            return response()->json([
                'status' => 'invalid',
                'message' => __('site.scan.invalid'),
            ], 422);
        }

        $group = ProductGroup::query()
            ->forMarket($current->get())
            ->where('identity_key', $gtin)
            ->first();

        Event::record('scan', [
            'market' => $current->value(),
            'gtin' => $gtin,
            'hit' => $group !== null,
        ]);

        if ($group === null) {
            /*
             * A miss is a real answer, not an error.
             *
             * The search falls back to the barcode as a query — SearchService
             * treats a GTIN as an exact identity and will also pull it from the
             * live sources, so a product we have never ingested can still turn
             * up from bol in the same request.
             */
            return response()->json([
                'status' => 'not_found',
                'gtin' => $gtin,
                'searchUrl' => $current->url('search').'?q='.$gtin,
                'message' => __('site.scan.not_found'),
            ]);
        }

        return response()->json([
            'status' => 'found',
            'gtin' => $gtin,
            'title' => $group->title,
            'image' => $group->image_url,
            'price' => $group->min_price,
            'merchantCount' => $group->merchant_count,
            'inStock' => $group->in_stock,
            'url' => $current->url("p/{$group->id}/{$group->slug}"),
            /*
             * Where a scan actually lands.
             *
             * The search rather than the product page, and searched by the
             * NORMALISED barcode: a camera reads a UPC-A as 12 digits, the
             * catalogue stores 13, and sending the raw read would find nothing
             * for every American product. Search also queries the live sources,
             * so a scan can surface an offer that has never been ingested.
             */
            'searchUrl' => $current->url('search').'?q='.$gtin,
        ]);
    }
}
