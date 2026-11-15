<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use App\Support\ListAccess;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One list, read-only, from the link in a mail about it, without signing in.
 *
 * Owner, 2026-11-15: the price update mail's link to the owner's own list
 * answered 404 to anybody not signed in, and then "open only the list". The
 * alternative asked about, a link that signs the reader in like a magic link,
 * was not built: the mail stays in an inbox for weeks and gets forwarded, and
 * whoever held it would be inside the account. This link opens one list and
 * nothing else.
 *
 * What the page shows: the list's title and its items with their current price
 * and a link to the product. What it never shows: who claimed what (the owner
 * of a wish list must not learn it, invariant 4), contributions, the delivery
 * address, the people the list is shared with, or any control that changes
 * something. Everything else is behind "Sign in", which comes back to the full
 * list.
 *
 * The access is a signed URL ({@see self::url()}), valid for {@see self::DAYS}
 * days: long enough for a mail read late, short enough that a forwarded one
 * stops working. A link that is expired or tampered with goes to the list's
 * ordinary address, which asks a signed-out visitor to sign in
 * (WishlistController::show()), so an old mail still leads somewhere useful.
 */
final class ListFromMailController extends Controller
{
    /** How long a link in a mail opens the list. */
    public const DAYS = 30;

    /** The link to put in a mail about this list. */
    public static function url(Wishlist $list): string
    {
        return URL::temporarySignedRoute(
            'lists.from-mail',
            now()->addDays(self::DAYS),
            ['market' => $list->market->value, 'list' => $list->id],
        );
    }

    public function __invoke(Request $request, CurrentMarket $current, string $market, string $list): Response|RedirectResponse
    {
        $full = $current->url("lists/{$list}");

        if (! $request->hasValidSignature()) {
            return redirect($full);
        }

        $wishlist = Wishlist::query()->with('items.group')->find($list);

        // A list kept without an account was never mailed about; a deleted one is gone.
        if ($wishlist === null || $wishlist->owner_user_id === null) {
            throw new NotFoundHttpException;
        }

        // Signed in with access already: the real page, with everything on it.
        if (ListAccess::allows($wishlist, Owner::fromRequest($request))) {
            return redirect($full);
        }

        // Signing in from here comes back to the full list (both sign-in routes end in intended()).
        $request->session()->put('url.intended', url($full));

        app(PageMeta::class)->set(
            title: $wishlist->displayTitle(),
            // A link that opens somebody's list is never a page for a search engine.
            robots: 'noindex, nofollow',
        );

        return Inertia::render('Lists/FromMail', [
            'list' => [
                'title' => $wishlist->displayTitle(),
                'items' => $wishlist->items
                    ->sortByDesc('created_at')
                    ->values()
                    ->map(fn (WishlistItem $item): array => [
                        'id' => $item->id,
                        'title' => $item->displayTitle(),
                        'image' => $item->group?->image_url ?? $item->snapshot_image_url,
                        // The live price, as the owner's own page shows it; null without a product.
                        'price' => $item->group?->min_price,
                        'inStock' => $item->group?->in_stock,
                        'url' => $item->productPath(),
                    ])
                    ->all(),
            ],
            'loginUrl' => $current->url('login'),
        ]);
    }
}
