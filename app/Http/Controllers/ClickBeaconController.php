<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Models\Event;
use App\Models\Product;
use App\Support\CurrentMarket;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Click tracking for links that cannot go through the redirector.
 *
 * Amazon requires Associates links to be direct and unobscured, so its offers
 * render as a plain anchor. That removes the natural place to record the click,
 * and click-outs are the only revenue signal this site has — so the browser
 * reports it instead, via `navigator.sendBeacon` on mousedown.
 *
 * Fire-and-forget by design: it returns 204 with no body, never blocks
 * navigation, and a failure loses one analytics row rather than a sale.
 */
class ClickBeaconController extends Controller
{
    public function __invoke(Request $request, CurrentMarket $current): Response
    {
        $validated = $request->validate([
            'offer' => ['required', 'integer'],
        ]);

        $product = Product::query()
            ->forMarket($current->get())
            ->whereKey($validated['offer'])
            ->whereIn('status', [ProductStatus::Active->value, ProductStatus::Stale->value])
            ->first();

        // Silently accept an unknown offer. This endpoint is fed by a beacon
        // the browser fires as the page unloads; turning a stale id into an
        // error would produce noise nobody can act on.
        if ($product !== null) {
            Event::create([
                'kind' => 'click_out',
                'market' => $current->value(),
                'user_id' => $request->user()?->id,
                'anon_id' => $request->attributes->get('anonymous_identity')?->getKey(),
                'payload' => [
                    'product_id' => $product->id,
                    'group_id' => $product->group_id,
                    'merchant_id' => $product->merchant_id,
                    'source' => $product->source->value,
                    'price' => $product->price,
                    // Distinguishes beacon-tracked clicks from redirector ones,
                    // which have different reliability: a beacon can be blocked
                    // or dropped, a redirect cannot.
                    'via' => 'beacon',
                ],
            ]);
        }

        return response()->noContent();
    }
}
