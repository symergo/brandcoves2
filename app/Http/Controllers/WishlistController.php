<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListVisibility;
use App\Models\Recipient;
use App\Models\Wishlist;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WishlistController extends Controller
{
    public function index(Request $request, CurrentMarket $current): Response
    {
        $owner = Owner::fromRequest($request);

        $lists = $owner->scope(Wishlist::query())
            ->with('recipient')
            ->withCount('items')
            ->latest('updated_at')
            ->get()
            ->map(fn (Wishlist $list) => $this->summarise($list, $current));

        return Inertia::render('Lists/Index', [
            'lists' => $lists,
            'recipients' => $owner->scope(Recipient::query())
                ->orderBy('name')
                ->get(['id', 'name', 'relationship', 'occasion']),
            'isSignedIn' => $owner->isSignedIn(),
        ]);
    }

    public function store(Request $request, CurrentMarket $current): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        abort_unless($owner->exists(), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'recipient_id' => ['nullable', 'uuid'],
            'is_gift_list' => ['boolean'],
        ]);

        // A recipient must belong to the same owner, or a guessed uuid would
        // attach someone else's person to this list.
        if (! empty($validated['recipient_id'])) {
            $owned = $owner->scope(Recipient::query())
                ->whereKey($validated['recipient_id'])
                ->exists();

            abort_unless($owned, 403);
        }

        $list = Wishlist::create([
            ...$owner->attributes(),
            'title' => $validated['title'],
            'market' => $current->get(),
            'recipient_id' => $validated['recipient_id'] ?? null,
            // A list for someone else is a gift list by default: that is the
            // whole reason to attach a recipient.
            'is_gift_list' => $validated['is_gift_list'] ?? ! empty($validated['recipient_id']),
        ]);

        return redirect()->to($current->url("lists/{$list->id}"));
    }

    public function show(Request $request, CurrentMarket $current, string $market, string $list): Response
    {
        $owner = Owner::fromRequest($request);

        $wishlist = $owner->scope(Wishlist::query())
            ->with(['recipient', 'items.group'])
            ->find($list);

        if ($wishlist === null) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('Lists/Show', [
            'list' => $this->summarise($wishlist, $current),
            'items' => $wishlist->items
                ->sortByDesc('priority')
                ->values()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->snapshot_title,
                    'image' => $item->snapshot_image_url,
                    'price' => $item->snapshot_price,
                    'note' => $item->note,
                    'groupId' => $item->group_id,
                    'slug' => $item->group?->slug,
                    // Current cheapest, so the owner sees whether it moved
                    // since they added it.
                    'currentPrice' => $item->group?->min_price,
                    'merchantCount' => $item->group?->merchant_count,
                    'inStock' => $item->group?->in_stock ?? false,
                    /*
                     * Claim state is DELIBERATELY absent.
                     *
                     * A gift list exists so the recipient does not learn what
                     * has been bought. This is the owner's view, so it must
                     * carry no hint — not a boolean, not a count, not an
                     * ordering difference.
                     */
                ]),
        ]);
    }

    public function update(Request $request, CurrentMarket $current, string $market, string $list): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        $wishlist = $owner->scope(Wishlist::query())->find($list);

        if ($wishlist === null) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'string', 'in:private,link,public'],
        ]);

        $wishlist->update($validated);

        return back();
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $list): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        $wishlist = $owner->scope(Wishlist::query())->find($list);

        if ($wishlist === null) {
            throw new NotFoundHttpException;
        }

        $wishlist->delete();

        return redirect()->to($current->url('lists'));
    }

    /** @return array<string, mixed> */
    private function summarise(Wishlist $list, CurrentMarket $current): array
    {
        return [
            'id' => $list->id,
            'title' => $list->title,
            'description' => $list->description,
            'isGiftList' => $list->is_gift_list,
            'visibility' => $list->visibility->value,
            'itemCount' => $list->items_count ?? $list->items()->count(),
            'recipient' => $list->recipient === null ? null : [
                'id' => $list->recipient->id,
                'name' => $list->recipient->name,
                'relationship' => $list->recipient->relationship,
            ],
            'url' => $current->url("lists/{$list->id}"),
            // Only meaningful once the list is shareable; the UI hides it
            // otherwise rather than offering a link that 404s.
            'shareUrl' => $list->visibility === ListVisibility::Private
                ? null
                : url($current->url("l/{$list->share_token}")),
        ];
    }
}
