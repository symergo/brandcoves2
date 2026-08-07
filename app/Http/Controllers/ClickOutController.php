<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Models\Event;
use App\Models\Product;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The click-out redirector.
 *
 * Every outbound link goes through here so that exactly one place is
 * responsible for validating a third-party URL before it becomes a Location
 * header, and so the click is recorded — click-outs are the only revenue signal
 * this site has.
 */
class ClickOutController extends Controller
{
    /** `{market}` is consumed by middleware but still passed positionally. */
    public function __invoke(Request $request, CurrentMarket $current, string $market, string $offer): RedirectResponse
    {
        $product = Product::query()
            ->forMarket($current->get())
            ->whereKey((int) $offer)
            ->whereIn('status', [ProductStatus::Active->value, ProductStatus::Stale->value])
            ->first();

        if ($product === null) {
            throw new NotFoundHttpException;
        }

        /*
         * Sources that require a direct, unobscured link must never be reachable
         * through the redirector — Amazon's terms are explicit about it, and a
         * redirector that quietly still works for them is exactly how a
         * hand-built or cached URL ends up violating the agreement.
         *
         * One path per source, enforced here rather than trusted to the view.
         */
        if ($product->source->requiresDirectLink()) {
            throw new NotFoundHttpException;
        }

        /*
         * THE INVARIANT.
         *
         * Affiliate URLs come from third-party feeds and are hostile input.
         * HTML escaping downstream would happily preserve `javascript:` or
         * `data:`, and this is the one place where such a string would become
         * a Location header the browser acts on.
         *
         * Checked here even though the ingestion path also rejects them: this
         * is the last line before the browser, and a future import path,
         * migration or admin edit must not be able to bypass it.
         */
        if (! $product->hasSafeAffiliateUrl()) {
            report(new \RuntimeException("Unsafe affiliate URL on product {$product->id}"));

            throw new NotFoundHttpException;
        }

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
                // The price at click time. Feeds move, and a conversion report
                // is unreadable without knowing what was on screen.
                'price' => $product->price,
            ],
        ]);

        // 302, not 301: the destination is a tracking URL that changes, and a
        // cached permanent redirect would send tomorrow's clicks to a dead link
        // and lose the attribution.
        return redirect()->away($product->affiliate_url, 302)
            // Do not leak the visitor's search terms to the merchant.
            ->withHeaders(['Referrer-Policy' => 'no-referrer']);
    }
}
