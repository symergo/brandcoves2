<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Source;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Rules\SafeExternalUrl;
use App\Services\Wishlist\DefaultList;
use App\Services\Wishlist\ItemSaver;
use App\Services\Wishlist\ListMaker;
use App\Support\CurrentMarket;
use App\Support\ListAccess;
use App\Support\Owner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WishlistItemController extends Controller
{
    /**
     * Which products are already on one of your lists.
     *
     * The save control is on every product card on every surface, and it knew
     * nothing until you clicked it — so something already on your wishlist
     * showed an empty bookmark, and the only way to find out was to save it
     * again.
     *
     * Ids only, and only for this market, so the payload stays small enough to
     * fetch once and hold. Any list of yours counts: a thing on your research
     * list for your mother is still a thing you have already found.
     */
    public function saved(Request $request, CurrentMarket $current): JsonResponse
    {
        $owner = Owner::fromRequest($request);

        if (! $owner->isSignedIn()) {
            return response()->json(['groupIds' => []]);
        }

        $ids = WishlistItem::query()
            ->whereNotNull('group_id')
            ->whereNotNull('accepted_at')
            ->whereHas('wishlist', fn ($q) => $owner->scope($q)->where('market', $current->value()))
            ->pluck('group_id')
            ->unique()
            ->values();

        return response()->json(['groupIds' => $ids]);
    }

    /**
     * Where a save could go.
     *
     * A plain JSON endpoint rather than an Inertia page, because the save
     * control lives on every product card on every surface — search, brand,
     * guides, the wizard, the daily edition — and sharing this through page
     * props would put a query on all of them for a control most visitors never
     * open. Fetched once, when somebody first opens the picker.
     *
     * Anonymous-first, like everything else about lists: the visitor may have
     * built all of these before signing up.
     */
    public function options(Request $request, CurrentMarket $current): JsonResponse
    {
        $owner = Owner::fromRequest($request);

        if (! $owner->exists()) {
            return response()->json(['lists' => [], 'recipients' => []]);
        }

        $lists = $owner->scope(Wishlist::query())
            ->where('market', $current->value())
            ->with('recipient')
            ->withCount('items')
            ->latest('updated_at')
            ->get();

        /*
         * Where this product already is.
         *
         * The picker could only ever add. Saving to the wrong list — easy,
         * since the rows are one line apart — left no way back except finding
         * the list, opening it and deleting the row there. Carrying the item id
         * lets the same row that put it there take it off again, and reuses
         * `destroy()` rather than growing a second delete path with its own
         * ownership check.
         */
        $group = $request->integer('group_id');

        $existing = $group === 0
            ? collect()
            : WishlistItem::query()
                ->whereIn('wishlist_id', $lists->pluck('id'))
                ->where('group_id', $group)
                ->whereNotNull('accepted_at')
                ->pluck('id', 'wishlist_id');

        return response()->json([
            'lists' => $lists->map(fn (Wishlist $list) => [
                'id' => $list->id,
                'title' => $list->title,
                // The distinction the picker is built around: a list for me and
                // a list about somebody else are different acts, and burying
                // both under "save" is what made this unusable.
                'kind' => $list->kind->value,
                'recipient' => $list->recipient?->name,
                'items' => $list->items_count,
                'itemId' => $existing[$list->id] ?? null,
            ])->values(),
            'recipients' => $owner->scope(Recipient::query())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->values(),
        ]);
    }

    /**
     * Add a product to a list.
     *
     * Creates the list on the fly when none is given, because the common path
     * is someone pressing "save" on a product page having never made a list —
     * asking them to create one first loses most of them.
     */
    public function store(Request $request, CurrentMarket $current, ItemSaver $saver): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        abort_unless($owner->exists(), 403);

        $manual = $request->input('source') === Source::Manual->value;

        $validated = $request->validate([
            // Either a group we hold, or a source + id we can re-fetch. A live
            // bol result and an Amazon product have neither a group nor a
            // stored price, and both are reachable from search.
            'group_id' => ['nullable', 'integer', 'required_without:source'],
            'source' => ['nullable', 'string', 'in:'.implode(',', Source::values()), 'required_without:group_id'],

            /*
             * `manual` is the one source with nothing to re-fetch: it is not a
             * product we can look up anywhere, so demanding an id it cannot have
             * would make the only hand-written entry impossible.
             */
            'external_id' => ['nullable', 'string', 'max:190', $manual ? 'prohibited' : 'required_with:source'],

            // For every other source this is a hint the saver may ignore. Here
            // it is the entire item, so it is required and it is what renders.
            'title' => [$manual ? 'required' : 'nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'url', 'max:1024'],
            'url' => ['nullable', 'string', 'max:2048', new SafeExternalUrl],
            'price' => ['nullable', 'integer', 'min:0'],
            'wishlist_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],

            /*
             * Create-and-save in one step.
             *
             * "Save this to a new list for my sister" is one intention, and
             * making somebody leave the product, create a list, come back and
             * find the product again is how the second list never gets made.
             */
            'new_list' => ['nullable', 'string', 'max:120'],
            'recipient_id' => ['nullable', 'uuid'],
            'new_recipient' => ['nullable', 'string', 'max:80'],

            // "Start a group gift" from the picker. See `ListMaker` for why
            // this is a boolean rather than a kind.
            'together' => ['boolean'],
        ]);

        $list = match (true) {
            filled($validated['new_list'] ?? null) => $this->createList($owner, $current, $validated),
            isset($validated['wishlist_id']) => ListAccess::scope(Wishlist::query(), $owner)->find($validated['wishlist_id']),
            default => $this->defaultList($owner, $current),
        };

        if ($list === null) {
            throw new NotFoundHttpException;
        }

        // A viewer was brought in to coordinate, not to curate.
        abort_unless(ListAccess::canEdit($list, $owner), 403);

        if (! empty($validated['group_id'])) {
            $group = ProductGroup::query()
                ->forMarket($current->get())
                ->find($validated['group_id']);

            if ($group === null) {
                throw new NotFoundHttpException;
            }

            $saver->saveGroup($list, $group, $current, $validated['note'] ?? null);

            return back()->with('success', $this->confirm($list));
        }

        /*
         * Something we do not sell.
         *
         * Before this, a list could only ever hold things we happen to stock —
         * so the honest answer to "a voucher for the climbing gym" was to leave
         * it off, and a list with the real present missing is a list that gets
         * abandoned. Nothing is fetched from the link; see `ItemSaver`.
         */
        if ($validated['source'] === Source::Manual->value) {
            $saver->saveManual(
                list: $list,
                title: $validated['title'],
                url: $validated['url'] ?? null,
                price: $validated['price'] ?? null,
                note: $validated['note'] ?? null,
            );

            return back()->with('success', $this->confirm($list));
        }

        /*
         * The snapshot fields are hints, not instructions.
         *
         * They arrive from the client, which means they arrive from whoever
         * chooses to POST here. `ItemSaver` decides per source whether any of
         * them may be stored at all — so a hand-built request naming Amazon
         * cannot smuggle a mirrored title and price into the catalogue.
         */
        $saver->saveExternal(
            list: $list,
            source: Source::from($validated['source']),
            externalId: $validated['external_id'],
            snapshot: [
                'title' => $validated['title'] ?? null,
                'image_url' => $validated['image_url'] ?? null,
                'price' => $validated['price'] ?? null,
            ],
            note: $validated['note'] ?? null,
        );

        return back()->with('success', $this->confirm($list));
    }

    /**
     * Say which list it went into.
     *
     * "Saved to your list" is true of every save and therefore answers nothing.
     * A save can land in the default list, in one chosen from the picker, or in
     * a list created in the same click — three destinations behind one word.
     * Naming the list is what makes the picker's default trustworthy enough to
     * accept without opening it.
     */
    private function confirm(Wishlist $list): string
    {
        return __('site.lists.added_to', ['list' => $list->title]);
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
            ->whereHas('wishlist', fn ($q) => ListAccess::scope($q, $owner))
            ->with('wishlist')
            ->first();

        if ($wishlistItem === null) {
            throw new NotFoundHttpException;
        }

        abort_unless(ListAccess::canEdit($wishlistItem->wishlist, $owner), 403);

        return $wishlistItem;
    }

    /**
     * A new list, made from the picker, with the product going straight into it.
     *
     * Delegates to {@see ListMaker}, which is also what the form on My Lists
     * uses — the recipient resolution and the kind decision were duplicated here
     * and would eventually have drifted apart, and a list whose kind disagrees
     * with its recipient is the ambiguity `ListKind` exists to remove.
     *
     * @param  array<string, mixed>  $validated
     */
    private function createList(Owner $owner, CurrentMarket $current, array $validated): Wishlist
    {
        return app(ListMaker::class)->make(
            owner: $owner,
            current: $current,
            title: $validated['new_list'],
            recipientId: $validated['recipient_id'] ?? null,
            newRecipient: $validated['new_recipient'] ?? null,
            together: (bool) ($validated['together'] ?? false),
        );
    }

    /**
     * The list a save lands in when the visitor has not chosen one.
     *
     * Always "My wishlist" — one row per owner, so "where did my save go?" has
     * exactly one answer. See {@see DefaultList}.
     */
    private function defaultList(Owner $owner, CurrentMarket $current): Wishlist
    {
        return app(DefaultList::class)->for($owner, $current);
    }
}
