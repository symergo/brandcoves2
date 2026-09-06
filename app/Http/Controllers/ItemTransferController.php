<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Wishlist\ItemMover;
use App\Services\Wishlist\ListMaker;
use App\Support\CurrentMarket;
use App\Support\ListAccess;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Put this on another list too", and "add what they asked for to mine".
 *
 * One verb — copy — and two sources, which is why there are two endpoints: a
 * row on a list of my own is reached by that list's id, and a row on somebody
 * else's is reached by their share token. What happens to the row is identical
 * and lives in {@see ItemMover}, along with the rule that matters: a claim
 * never travels.
 *
 * ## Neither endpoint touches the source list
 *
 * Copy is the only operation, so nothing is ever removed. That collapses the
 * gate: the source needs to be *readable*, the destination *writable*, and a
 * mistaken press costs one row somebody can delete rather than one row nobody
 * can recover. Removal already exists on every item, so "move" would be a
 * second way to do what the page can already do in two presses — with a failure
 * mode the copy does not have.
 *
 * ## Why a picker rather than dragging
 *
 * Drag and drop was the obvious shape and is the wrong one. It is unusable with
 * a keyboard, invisible to a screen reader, and on a phone it fights the scroll
 * — on the device most of this happens on, a long press that sometimes scrolls
 * and sometimes lifts is worse than no feature at all.
 *
 * So: a control on the row and a list of destinations. It works by touch, by
 * keyboard and by screen reader, and it names the destination in words rather
 * than asking somebody to aim at it.
 */
class ItemTransferController extends Controller
{
    public function __construct(private readonly ItemMover $mover) {}

    /**
     * Copy an item from one of my lists onto another.
     *
     * The source is only read, so reading access is all it needs —
     * `ListAccess::scope()` already unions the lists I own with the ones I have
     * been let into. The destination is written to, so it wants `canEdit()`.
     */
    public function between(
        Request $request,
        CurrentMarket $current,
        string $market,
        string $list,
        WishlistItem $item,
    ): RedirectResponse {
        $owner = Owner::fromRequest($request);

        $from = $this->readable($list, $owner);

        if ($item->wishlist_id !== $from->id) {
            // The row is none of this URL's business either way.
            throw new NotFoundHttpException;
        }

        $validated = $request->validate($this->destinationRules());

        $to = $this->destination($validated, $owner, $current);

        // Copying a row onto the list it is already on is a duplicate somebody
        // would have to tidy.
        if ($to->id === $from->id) {
            return back();
        }

        $this->mover->copy($item, $to);

        return back()->with('success', __('site.lists.copied_to', ['list' => $to->displayTitle()]));
    }

    /**
     * Copy something off the recipient's own list onto the list you asked from.
     *
     * The payoff of the Ask panel: their list is readable there, and every row
     * on it is a thing the giver wants on the list they are working from.
     *
     * Reached by the source list's **share token**, exactly as claiming from
     * that list is. The token is the permission — whoever holds it may read the
     * list, and taking a copy of a row is a read. Nothing on their list changes.
     */
    public function fromShared(
        Request $request,
        CurrentMarket $current,
        string $market,
        string $token,
        WishlistItem $item,
    ): RedirectResponse {
        $owner = Owner::fromRequest($request);

        $source = Wishlist::query()
            ->where('share_token', $token)
            ->where('visibility', '!=', 'private')
            ->first();

        if ($source === null || $item->wishlist_id !== $source->id) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validate($this->destinationRules());

        $to = $this->destination($validated, $owner, $current);

        $this->mover->copy($item, $to);

        return back()->with('success', __('site.lists.copied_to', ['list' => $to->displayTitle()]));
    }

    /** A list this person may see, or a 404. */
    private function readable(string $id, Owner $owner): Wishlist
    {
        $list = ListAccess::scope(Wishlist::query(), $owner)->whereKey($id)->first();

        if ($list === null) {
            throw new NotFoundHttpException;
        }

        return $list;
    }

    /**
     * A list this person may write to, or a 404.
     *
     * A 404 rather than a 403 for a list that is not theirs at all: whether a
     * uuid names a real list is not something an endpoint should confirm to
     * somebody who cannot see it.
     */
    /**
     * Where a copy may go: a list you already have, or one named on the spot.
     *
     * `new_list` exists so the copy control can be the same control as the save
     * picker, which has always been able to create a list mid-save. Without it,
     * somebody with no lists yet met a menu with nothing in it — the copy
     * control could only ever file into a list that already existed, so the two
     * pages had to draw two different things.
     *
     * @return array<string, mixed>
     */
    private function destinationRules(): array
    {
        return [
            'to' => ['required_without:new_list', 'nullable', 'uuid'],
            'new_list' => ['required_without:to', 'nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Resolve that into a list this person may write to.
     *
     * A named list is made through {@see ListMaker}, which is what the save
     * picker and the form on My Lists both use — the kind decision and the
     * recipient resolution live there, and a third copy of them here is how a
     * list whose kind disagrees with its recipient gets created.
     *
     * @param  array<string, mixed>  $validated
     */
    private function destination(array $validated, Owner $owner, CurrentMarket $current): Wishlist
    {
        if (filled($validated['new_list'] ?? null)) {
            return app(ListMaker::class)->make(
                owner: $owner,
                current: $current,
                title: $validated['new_list'],
            );
        }

        return $this->writable($validated['to'], $owner);
    }

    private function writable(string $id, Owner $owner): Wishlist
    {
        $list = $this->readable($id, $owner);

        if (! ListAccess::canEdit($list, $owner)) {
            throw new NotFoundHttpException;
        }

        return $list;
    }
}
