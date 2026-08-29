<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Models\Wishlist;
use App\Support\ListAccess;
use App\Support\Owner;
use Illuminate\Contracts\Session\Session;

/**
 * "Adding to Camping" — the list you are currently filling.
 *
 * ## The problem it removes
 *
 * Filling one list used to cost a re-decision per product. "Find things to add"
 * pointed at a bare search page that knew nothing about where you had come
 * from, so every card cost: open the picker, wait for `/list-options`, scan
 * three sections, find the same list you chose a moment ago, tap it. Ten items,
 * ten identical decisions. The destination is the *least* variable part of
 * filling a list and it was being asked about the most.
 *
 * ## Why the session and not a query parameter
 *
 * `?to={list}` was the obvious alternative and is worse. It would have to
 * survive search pagination, every facet link, sort changes, a click into a
 * product and back, a guide, a brand page — every internal link on every
 * discovery surface. Any one of them that forgot to carry it drops the mode
 * silently, and the visitor finds out by looking at their list afterwards.
 *
 * The session carries it through all of that for free. What the session cannot
 * do is *show* the mode, which is why it is always accompanied by a bar naming
 * the list. A mode you can see at all times cannot surprise you, and that bar
 * is the real safeguard here — not an expiry.
 *
 * ## Why the id and not the title
 *
 * Only the id is stored; the title is resolved per request. That costs one
 * primary-key lookup, and only while the mode is on. In exchange the mode is
 * self-healing: rename the list and the bar follows, delete it or lose access
 * and the mode quietly ends rather than pointing at something that is not there.
 */
class AddingMode
{
    private const KEY = 'wishlist.adding_to';

    public function __construct(private readonly Session $session) {}

    public function start(Wishlist $list): void
    {
        $this->session->put(self::KEY, $list->id);
    }

    public function stop(): void
    {
        $this->session->forget(self::KEY);
    }

    /** The id alone, without touching the database. */
    public function listId(): ?string
    {
        $id = $this->session->get(self::KEY);

        return is_string($id) ? $id : null;
    }

    /**
     * The list being filled, if there still is one this owner may edit.
     *
     * Re-checks `canEdit` on every request rather than trusting the session.
     * Access can be taken away — a collaborator demoted to viewer, a list handed
     * over — and a mode that outlives permission would send every subsequent
     * save into a 403 with no explanation on screen.
     *
     * @return array{id: string, title: string}|null
     */
    public function current(Owner $owner): ?array
    {
        $id = $this->listId();

        if ($id === null || ! $owner->exists()) {
            return null;
        }

        $list = ListAccess::scope(Wishlist::query(), $owner)->find($id);

        if ($list === null || ! ListAccess::canEdit($list, $owner)) {
            // Ended, rather than left to fail on the next save.
            $this->stop();

            return null;
        }

        return ['id' => $list->id, 'title' => $list->displayTitle()];
    }
}
