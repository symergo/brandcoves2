<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Source;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Rules\SafeExternalUrl;
use App\Services\Connectors\Offer;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
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
     *
     * ## `?list=` — the same question about one list
     *
     * While somebody is filling a named list, "have I kept this anywhere?" is
     * the wrong question: it ticks items that are on a different list and hides
     * the ones actually added during this run. `listGroupIds` answers the right
     * one, in the same round trip rather than a second request per card.
     *
     * Scoped through `ListAccess`, so asking about a list you have no part in
     * returns an empty set rather than its contents — this is a read of
     * somebody's list membership and has to be gated like one.
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

        $list = $request->query('list');

        if (! is_string($list) || $list === '') {
            return response()->json(['groupIds' => $ids]);
        }

        $onList = ListAccess::scope(Wishlist::query(), $owner)->whereKey($list)->exists()
            ? WishlistItem::query()
                ->where('wishlist_id', $list)
                ->whereNotNull('group_id')
                ->whereNotNull('accepted_at')
                ->pluck('group_id')
                ->unique()
                ->values()
            : collect();

        return response()->json(['groupIds' => $ids, 'listGroupIds' => $onList]);
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
                'title' => $list->displayTitle(),
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
     * Find something to put on a list, without leaving the list.
     *
     * ## Why the list page needed its own search
     *
     * Filling a list meant leaving it: "find things to add" navigated to the
     * search page, and "add something yourself" was a separate form for the
     * separate case where the catalogue does not have it. Two buttons for one
     * intention — *put a thing on this list* — and the split was ours, not the
     * visitor's. They are one control now, and this is the half that searches.
     *
     * Stored and live in one answer, because the difference is ours too. The
     * `groups` half is the catalogue (including anything a live source folded
     * in a moment ago); `live` is what may not be mirrored at all — Amazon —
     * and so has no group to point at. `SearchService` already draws that line;
     * this only forwards it.
     *
     * `storable` travels with each live row because it decides what the client
     * may offer: for a source we may not mirror, a title the owner types would
     * be discarded at render (invariant #6), and a field that silently does
     * nothing is worse than an absent one.
     */
    public function find(Request $request, CurrentMarket $current, SearchService $search): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        // Two characters is where a search stops being a keystroke. Below it
        // every term matches half the catalogue and every live connector is
        // asked a question with no answer, which costs a request each time.
        if (mb_strlen($term) < 2) {
            return response()->json(['groups' => [], 'live' => []]);
        }

        $result = $search->search(new SearchQuery(
            market: $current->get(),
            term: $term,
            /*
             * Both defaults are wrong here and both are wrong quietly.
             * `discountedOnly` defaults to true, which would silently limit a
             * gift list to whatever happens to be reduced today; `inStockOnly`
             * stays on, because putting something unbuyable on a list is how a
             * list disappoints somebody later.
             */
            discountedOnly: false,
            /*
             * Not public demand. `search_log` feeds the related-search chips on
             * public pages and the queue that decides which buying guides get
             * written — and a term typed while filling "Cadeau voor mama" is
             * about one named person. Same opt-out, and the same reason, as the
             * search box inside a shared list.
             */
            logged: false,
        ));

        return response()->json([
            'groups' => array_map(fn (ProductGroup $group) => [
                'id' => $group->id,
                'title' => $group->title,
                'image' => $group->image_url,
                'price' => $group->min_price,
                'brand' => $group->brand,
                'merchantCount' => $group->merchant_count,
            ], array_slice($result->groups->items(), 0, 8)),

            'live' => array_map(fn (Offer $offer) => [
                'source' => $offer->source->value,
                'externalId' => $offer->externalId,
                'title' => $offer->title,
                'image' => $offer->imageUrl,
                'price' => $offer->price,
                'merchant' => $offer->merchantName ?? $offer->source->label(),
                'storable' => $offer->source->allowsCatalogueStorage(),
            ], array_slice($result->liveOffers, 0, 4)),
        ]);
    }

    /**
     * Add a product to a list.
     *
     * Creates the list on the fly when none is given, because the common path
     * is someone pressing "save" on a product page having never made a list —
     * asking them to create one first loses most of them.
     */
    public function store(Request $request, CurrentMarket $current, ItemSaver $saver): RedirectResponse|JsonResponse
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

            return $this->report(
                $request,
                $saver->saveGroup(
                    list: $list,
                    group: $group,
                    current: $current,
                    note: $validated['note'] ?? null,
                    // Owner-written wording. A feed title is written for a
                    // search engine; a list is read by a person. See ItemSaver.
                    title: $validated['title'] ?? null,
                ),
                $list,
            );
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
            return $this->report($request, $saver->saveManual(
                list: $list,
                title: $validated['title'],
                url: $validated['url'] ?? null,
                price: $validated['price'] ?? null,
                note: $validated['note'] ?? null,
            ), $list);
        }

        /*
         * The snapshot fields are hints, not instructions.
         *
         * They arrive from the client, which means they arrive from whoever
         * chooses to POST here. `ItemSaver` decides per source whether any of
         * them may be stored at all — so a hand-built request naming Amazon
         * cannot smuggle a mirrored title and price into the catalogue.
         */
        return $this->report($request, $saver->saveExternal(
            list: $list,
            source: Source::from($validated['source']),
            externalId: $validated['external_id'],
            snapshot: [
                'title' => $validated['title'] ?? null,
                'image_url' => $validated['image_url'] ?? null,
                'price' => $validated['price'] ?? null,
            ],
            note: $validated['note'] ?? null,
        ), $list);
    }

    /**
     * Report a save, in whichever shape the caller can use.
     *
     * ## Why there are two shapes
     *
     * The save control sits on a product card on every discovery surface, and
     * answering it with `back()` made one bookmark tap a full Inertia round
     * trip: the page's props are rebuilt — a forty-result search re-run — to
     * move a single row. Worse, the confirmation then arrives through `flash`,
     * which `FlashMessage` draws as an in-flow banner at the top of the page.
     * Saving from the bottom of a grid therefore rendered the confirmation
     * off-screen *and* pushed the grid down under the cursor.
     *
     * The JSON branch also carries `itemId`, which is what makes an Undo
     * possible at all: a flash string names the list and cannot name the row,
     * so undoing meant reopening the picker and hunting for the tick.
     *
     * `back()` stays for ordinary form posts — `ManualItem` and the list pages
     * submit through Inertia and do want the page to come back.
     */
    private function report(Request $request, WishlistItem $item, Wishlist $list): RedirectResponse|JsonResponse
    {
        if (! $request->expectsJson()) {
            return back()->with('success', $this->confirm($list));
        }

        return response()->json([
            'itemId' => $item->id,
            'listId' => $list->id,
            'listTitle' => $list->displayTitle(),
            'message' => $this->confirm($list),
        ]);
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
        return __('site.lists.added_to', ['list' => $list->displayTitle()]);
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

    public function destroy(Request $request, CurrentMarket $current, string $market, string $item): RedirectResponse|JsonResponse
    {
        $this->findOwned($request, $item)->delete();

        // Both shapes, for the same reason as `saved()` — and this is also the
        // path an Undo takes, which is an XHR by definition.
        if ($request->expectsJson()) {
            return response()->json(['message' => __('site.lists.removed')]);
        }

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
