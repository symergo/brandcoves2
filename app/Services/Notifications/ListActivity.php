<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Wishlist\Board;
use App\Support\Owner;

/**
 * Telling people what happened on a list, without telling them what they must not know.
 *
 * ## Why this is a service and not six `Notification::create()` calls
 *
 * Every one of these events happens in a controller that already knows who did
 * it and to which list — so writing the row there is the obvious thing, and it
 * is how the privacy rule gets broken. Invariant #4 says the person a wish list
 * is *for* never learns what has been claimed; a notification is the one channel
 * that goes and finds them. A "somebody claimed something" row in an inbox is
 * exactly the disclosure the whole feature exists to prevent, and it would be
 * written by whichever controller was added last by somebody who had not read
 * this file.
 *
 * So the gate lives here, once, beside the thing it gates.
 *
 * ## The rules, in one place
 *
 * | event | who hears about it |
 * |---|---|
 * | a list was shared with you | you |
 * | somebody suggested an item | the owner — a suggestion is addressed to them |
 * | somebody added an item | the owner, whose list changed under them |
 * | somebody wrote on the board | only those who may *see* that board |
 * | somebody chipped in | the organiser of the group gift |
 * | somebody claimed something | **only where claims are already visible** |
 *
 * The last row is the one to read twice. It is `shouldHideClaimsFrom()`, the
 * same question `ClaimView` and `Board` ask, so a wish list's owner is never
 * told — not as a count, not as a name, not at all — and a gift list's owner,
 * who is a co-giver and already sees claim state on the page, is.
 *
 * ## One line, and both names in quotes
 *
 * "Iemand zette iets op voor verjaardag samenleggen" failed twice over: it never
 * said *what* was added, and the list name — a lowercase phrase starting with a
 * preposition — welded onto the sentence so the reader could not tell where the
 * title began. Nothing constrains what somebody calls a list or a product, so
 * both are quoted and the sentence survives whatever they are called:
 *
 *     Iemand voegde toe aan "voor verjaardag samenleggen": "LEGO Millennium Falcon"
 *
 * The quotes are the whole mechanism. Splitting it across two lines was tried
 * first and read as two separate facts.
 *
 * ## Never to yourself
 *
 * Every method drops the notification when the actor is the recipient. Your own
 * inbox telling you what you just did is noise on every surface, and here it is
 * also a privacy leak in miniature: the owner of a wish list adding an item
 * would otherwise get a row proving the feature can reach them.
 */
class ListActivity
{
    /** `Anna shared "Wedding" with you.` */
    public function shared(Wishlist $list, User $from, User $to, string $url): void
    {
        $this->write($to, $from, 'list.shared', __('site.notifications.list_shared', [
            'name' => $from->displayName(),
            'list' => $list->displayTitle(),
        ]), $url, $list->id);
    }

    /**
     * `A new suggestion for "Wedding": "A nice mug"`
     *
     * The owner, and only the owner: a suggestion waits for their decision, and
     * nobody else on the list is being asked anything.
     *
     * The item is named because "somebody suggested something" sends you to the
     * page to find out what — and it is the owner's own list, where they will
     * see it anyway. The *suggester* is not: a suggestion can arrive from
     * anybody holding the link, and a stranger's chosen display name in an inbox
     * is a channel worth not opening.
     */
    public function suggested(Wishlist $list, Owner $from, ?string $item = null): void
    {
        $this->toOwner($list, $from, 'list.suggestion', 'list_suggestion', $item);
    }

    /** `Someone added to "Wedding": "A nice mug"` — their list changed under them. */
    public function itemAdded(Wishlist $list, Owner $from, ?string $item = null): void
    {
        $this->toOwner($list, $from, 'list.item_added', 'list_item_added', $item);
    }

    /**
     * `A new message on "Wedding"`
     *
     * Only to people who may see that board at all — which on a wish list means
     * **not its owner**, because a board is claim state in prose and its owner
     * is the person being surprised. `Board::visibleTo()` is the question, asked
     * of the same `Owner` shape it takes everywhere else.
     *
     * The message itself is never quoted: it is free text written by whoever
     * holds the link, and an inbox is not the place to forward it.
     */
    public function posted(Wishlist $list, Owner $from, Board $board): void
    {
        $to = $this->ownerOf($list);

        if ($to === null || ! $board->visibleTo($list, new Owner(user: $to, anonymous: null))) {
            return;
        }

        $this->toOwner($list, $from, 'list.message', 'list_message');
    }

    /**
     * `Someone chipped in for "Leaving present"`
     *
     * The organiser fronts the money and collects it, so a pledge is addressed
     * to them. The amount stays out of the sentence: it is the one figure the
     * pot keeps private, and an inbox is read in more places than a page is.
     */
    public function pledged(Wishlist $list, Owner $from): void
    {
        $this->toOwner($list, $from, 'list.pledge', 'list_pledge');
    }

    /**
     * `Spoken for on "Dad's birthday": "The scarf"`
     *
     * **The invariant #4 gate, and the only reason this method is careful.**
     * `shouldHideClaimsFrom()` is the same question the page asks before drawing
     * claim state at all: false for the owner of a gift list, who is a co-giver
     * and already sees which items are taken, and true for the owner of a wish
     * list, who must never learn it.
     *
     * The item **is** named, and that is safe precisely because of that gate:
     * the only person who receives this is one the page already shows claim
     * state to. "Something has been spoken for" sent them to the list to find
     * out which — identical information, one trip further away. The gate carries
     * the privacy here, not the vagueness; softening the words while leaving the
     * wrong person on the receiving end would have been the dangerous version.
     */
    public function claimed(Wishlist $list, Owner $from, ?string $item = null): void
    {
        $to = $this->ownerOf($list);

        if ($to === null || $list->shouldHideClaimsFrom(new Owner(user: $to, anonymous: null))) {
            return;
        }

        $this->toOwner($list, $from, 'list.claimed', 'list_claimed', $item);
    }

    /**
     * The five that go to the list's owner and name the list.
     *
     * `$key` and `"{$key}_item"` are the two shapes every one of these sentences
     * has: with the thing that happened named, and without it when the caller
     * has nothing to name. Choosing between them once is what keeps the methods
     * above down to their gate and their intent.
     */
    private function toOwner(Wishlist $list, Owner $from, string $kind, string $key, ?string $item = null): void
    {
        $to = $this->ownerOf($list);

        if ($to === null) {
            return;
        }

        $title = $item === null
            ? __("site.notifications.{$key}", ['list' => $list->displayTitle()])
            : __("site.notifications.{$key}_item", ['list' => $list->displayTitle(), 'item' => $item]);

        $this->write($to, $from->user, $kind, $title, $this->ownerUrl($list), $list->id);
    }

    /**
     * The list's owner, without tripping over a relation nobody loaded.
     *
     * Lazy loading is **disabled** in this application, so `$list->owner` on a
     * list that arrived from a query which did not eager-load it is not a
     * silent extra query — it is a `LazyLoadingViolationException`, i.e. a 500
     * on somebody's claim. Every caller here is a controller that loaded the
     * list for its own reasons and cannot be relied on to have wanted the owner
     * too.
     *
     * Null for an anonymous owner, who has no inbox to write to.
     */
    private function ownerOf(Wishlist $list): ?User
    {
        if ($list->owner_user_id === null) {
            return null;
        }

        return $list->relationLoaded('owner') ? $list->owner : User::query()->find($list->owner_user_id);
    }

    /**
     * The owner's own page for a list.
     *
     * Not the share link: this row lands in the inbox of the person who owns
     * the list, and sending them to the visitor's view of their own list is how
     * the earlier "my list looks read-only" report happened.
     */
    private function ownerUrl(Wishlist $list): string
    {
        return '/'.$list->market->value.'/lists/'.$list->id;
    }

    /**
     * One row, unless the actor is the person being told.
     *
     * `$actor` is nullable because plenty of these are done by somebody holding
     * a link with no account at all — and an anonymous actor is by definition
     * not the recipient, so the comparison simply does not fire.
     *
     * `body` stays null throughout: the title is the whole sentence. The inbox
     * renders a second line only for the kinds that have one, which is what
     * stopped every list notification reading "dropped to - (was -)".
     */
    private function write(User $to, ?User $actor, string $kind, string $title, string $url, string $listId): void
    {
        if ($actor !== null && $actor->id === $to->id) {
            return;
        }

        Notification::create([
            'user_id' => $to->id,
            'kind' => $kind,
            'title' => $title,
            'body' => null,
            'url' => $url,
            /*
             * The list's id, not its address. `payload` is what a later reader
             * queries on — "has this person already been told about this list
             * today" — and a URL is a string that changes whenever routing does.
             * `SendOccasionReminders` keys its dedupe off `payload->key` for the
             * same reason.
             */
            'payload' => ['wishlist_id' => $listId],
        ]);
    }
}
