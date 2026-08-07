<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WishlistItemController extends Controller
{
    /**
     * Add a product to a list.
     *
     * Creates the list on the fly when none is given, because the common path
     * is someone pressing "save" on a product page having never made a list —
     * asking them to create one first loses most of them.
     */
    public function store(Request $request, CurrentMarket $current): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        abort_unless($owner->exists(), 403);

        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
            'wishlist_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $group = ProductGroup::query()
            ->forMarket($current->get())
            ->find($validated['group_id']);

        if ($group === null) {
            throw new NotFoundHttpException;
        }

        $list = isset($validated['wishlist_id'])
            ? $owner->scope(Wishlist::query())->find($validated['wishlist_id'])
            : $this->defaultList($owner, $current);

        if ($list === null) {
            throw new NotFoundHttpException;
        }

        /*
         * A snapshot, not just a reference.
         *
         * A feed can drop this product tomorrow, or rename it. The list must
         * still show what the person actually chose, at the price they saw —
         * that is the record of their decision, and it should not silently
         * rewrite itself.
         */
        WishlistItem::updateOrCreate(
            ['wishlist_id' => $list->id, 'group_id' => $group->id],
            [
                'snapshot_title' => $group->title,
                'snapshot_image_url' => $group->image_url,
                'snapshot_price' => $group->min_price,
                'snapshot_url' => $current->url("p/{$group->id}/{$group->slug}"),
                'note' => $validated['note'] ?? null,
            ],
        );

        $list->touch();

        return back()->with('success', __('site.lists.added'));
    }

    public function update(Request $request, CurrentMarket $current, string $market, string $item): RedirectResponse
    {
        $wishlistItem = $this->findOwned($request, $item);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'priority' => ['nullable', 'integer', 'between:-2,2'],
        ]);

        $wishlistItem->update($validated);

        return back();
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $item): RedirectResponse
    {
        $this->findOwned($request, $item)->delete();

        return back()->with('success', __('site.lists.removed'));
    }

    private function findOwned(Request $request, string $item): WishlistItem
    {
        $owner = Owner::fromRequest($request);

        $wishlistItem = WishlistItem::query()
            ->whereKey($item)
            // Ownership is on the list, not the item, so it has to be joined
            // through — otherwise any guessed item id would be editable.
            ->whereHas('wishlist', fn ($q) => $owner->scope($q))
            ->first();

        if ($wishlistItem === null) {
            throw new NotFoundHttpException;
        }

        return $wishlistItem;
    }

    /** The list a save lands in when the visitor has not chosen one. */
    private function defaultList(Owner $owner, CurrentMarket $current): Wishlist
    {
        $existing = $owner->scope(Wishlist::query())
            ->where('market', $current->value())
            ->where('is_gift_list', false)
            ->oldest()
            ->first();

        return $existing ?? Wishlist::create([
            ...$owner->attributes(),
            'title' => __('site.lists.default_title'),
            'market' => $current->get(),
            'is_gift_list' => false,
        ]);
    }
}
