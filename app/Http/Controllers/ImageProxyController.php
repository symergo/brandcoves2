<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Images\ImageProxy;
use App\Services\Images\ProxiedImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/img/{width}/{signature}/{source}`: a merchant's picture, resized to WebP.
 *
 * What is refused, and how:
 *
 * - a bad signature, a width not on the list, a source that is not https, not
 *   on the host list or Amazon's: 404, plain, with nothing fetched. The page
 *   never builds such an address, so nobody but a prober sees it.
 * - a picture that cannot be had (the shop answers 404, too large, not a
 *   picture, a timeout), or a visitor over the fetch limit: a redirect to the
 *   original URL, which is what the page used before this route existed. The
 *   browser still gets its picture, from the shop, as it always did. The
 *   original is safe to redirect to because it is signed and checked above:
 *   this is not an open redirect.
 *
 * A stored copy is served from disk and costs no fetch and no limiter tick;
 * only a copy that has to be made counts against the visitor.
 */
class ImageProxyController extends Controller
{
    public function __invoke(
        Request $request,
        int $width,
        string $signature,
        string $source,
        ImageProxy $proxy,
        ProxiedImages $images,
    ): BinaryFileResponse|RedirectResponse {
        if (! $proxy->allowsWidth($width)) {
            throw new NotFoundHttpException;
        }

        $url = $proxy->verify($signature, $source);

        // Signed by us once is not enough: the host list may have shrunk since,
        // and Amazon is refused whatever the list says.
        if ($url === null || ! $proxy->proxiable($url)) {
            throw new NotFoundHttpException;
        }

        // Switched off: addresses already in a browser's cache or a cached page
        // still lead to the picture, from the shop, and nothing is fetched.
        if (! $proxy->enabled()) {
            return $this->original($url);
        }

        $file = $images->cached($url, $width);

        if ($file === null) {
            if ($images->recentlyFailed($url) || ! $this->mayFetch($request)) {
                return $this->original($url);
            }

            $file = $images->make($url, $width);

            if ($file === null) {
                return $this->original($url);
            }
        }

        return response()->file($file, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            // The address is the source URL and the width; the copy behind it
            // only changes when the shop changes the picture at the same URL,
            // which is rare enough to answer with the prune (see ProxiedImages).
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Two budgets for making copies, per visitor and for the whole site, so
     * neither one browser nor a crawler walking every page can turn this route
     * into a stream of outbound requests. Over either, the visitor is sent to
     * the original instead: slower, never broken.
     */
    private function mayFetch(Request $request): bool
    {
        $perVisitor = (int) config('giftcoves.image_proxy.fetches_per_minute', 120);
        $total = (int) config('giftcoves.image_proxy.fetches_per_minute_total', 1200);

        if (RateLimiter::tooManyAttempts('image-proxy:ip:'.$request->ip(), $perVisitor)
            || RateLimiter::tooManyAttempts('image-proxy:all', $total)) {
            return false;
        }

        RateLimiter::hit('image-proxy:ip:'.$request->ip(), 60);
        RateLimiter::hit('image-proxy:all', 60);

        return true;
    }

    private function original(string $url): RedirectResponse
    {
        // Short: the copy may exist a minute from now, and the next visit should
        // get it rather than this detour for a year.
        return redirect()->away($url, 302, ['Cache-Control' => 'public, max-age=300']);
    }
}
