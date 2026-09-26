<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Wishlist;
use App\Services\Cove\CommunityCoves;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Publish a list as a Community Cove, change its public title, or take it off
 * the site (docs/features/community-coves.md).
 *
 * The owner only, and an owner with an account: a Cove on the site has to
 * belong to somebody who can come back and take it down. A collaborator, even
 * an editor, may not publish somebody else's list.
 */
class ListPublishController extends Controller
{
    public function store(Request $request, CurrentMarket $current, CommunityCoves $coves, string $market, string $list): RedirectResponse
    {
        $wishlist = $this->owned($request, $list);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:'.CommunityCoves::MAX_TITLE],
            'show_owner' => ['sometimes', 'boolean'],
        ]);

        $problem = $coves->problemWith($wishlist, $validated['title']);

        if ($problem !== null) {
            throw ValidationException::withMessages([
                'title' => __('site.community.'.$problem, ['count' => CommunityCoves::MIN_ITEMS]),
            ]);
        }

        $first = $wishlist->published_at === null;
        $coves->publish($wishlist, $validated['title'], (bool) ($validated['show_owner'] ?? false));

        return back()->with('success', __($first ? 'site.community.published' : 'site.community.updated'));
    }

    public function destroy(Request $request, CurrentMarket $current, CommunityCoves $coves, string $market, string $list): RedirectResponse
    {
        $coves->unpublish($this->owned($request, $list));

        return back()->with('success', __('site.community.unpublished'));
    }

    private function owned(Request $request, string $list): Wishlist
    {
        $wishlist = Wishlist::query()
            ->where('owner_user_id', $request->user()->id)
            ->with(['recipient', 'items.group'])
            ->find($list);

        if ($wishlist === null) {
            throw new NotFoundHttpException;
        }

        return $wishlist;
    }
}
