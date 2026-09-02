<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\ListVisibility;
use App\Models\ListMessage;
use App\Models\Wishlist;
use App\Support\Owner;
use Illuminate\Support\Collection;

/**
 * Who may read and write the discussion beside a list.
 *
 * One service rather than a condition in two controllers, for the reason
 * {@see ClaimView} exists: the question has one right answer and two pages ask
 * it — the owner's `/lists/{id}` and the visitor's `/l/{token}` — and a rule
 * copied into both is a rule that will be right in one of them.
 *
 * ## The gate is the claim gate
 *
 * A board is free text written by the people doing the buying, and "I've got
 * the scarf, someone take the boots" is claim state in prose. So it hangs off
 * `Wishlist::shouldHideClaimsFrom()`, which is where invariant #4 lives, rather
 * than off a second rule that would have to be kept in step with it:
 *
 * | the list | its owner | anybody with the link |
 * |---|---|---|
 * | a wish list, claims hidden (the default) | **no board at all** | reads and writes |
 * | a wish list, owner asked to see claims | reads and writes | reads and writes |
 * | about somebody else | reads and writes | reads and writes |
 * | a group gift | reads and writes — they are the organiser | reads and writes |
 *
 * The first row is the one that matters. The owner of a wish list is the person
 * being surprised; showing them a thread in which their friends divide up the
 * shopping would undo the feature the list exists for, and it would do it in
 * prose that no claim-hiding code path inspects.
 *
 * The last row needed saying out loud, because `shouldHideClaimsFrom()` alone
 * gets it wrong — see `visibleTo()`.
 *
 * ## And a private list has no board
 *
 * Not because anything would leak — nobody else can reach it — but because a
 * discussion with one participant is a notes field, and there is one of those
 * under the title.
 */
class Board
{
    /**
     * How many messages a board shows.
     *
     * A rail is not a chat client. Fifty is more than any of these
     * conversations has ever needed — six people settling who buys what — and
     * the cap is here so that the one that goes wrong cannot make the page
     * enormous. Oldest-first within the window, so it reads as a conversation.
     */
    private const LIMIT = 50;

    /**
     * Is there a board on this list for this viewer at all?
     *
     * Asked by both pages before they render the rail, and by the endpoint
     * before it writes — so the control and the POST answer the same question,
     * and a control that 403s when pressed never exists.
     */
    public function visibleTo(Wishlist $list, Owner $viewer): bool
    {
        return $list->visibility !== ListVisibility::Private
            && (
                /*
                 * The group inversion, and it is not optional here.
                 *
                 * `shouldHideClaimsFrom()` alone hid the board from the
                 * organiser of a group gift — because `ownerSeesClaimsByDefault()`
                 * is true only for `for_someone`, a default that predates group
                 * lists having a pot. On a group list the owner IS the
                 * organiser and the recipient is a third party who never opens
                 * the page, so there is no surprise to protect from them; they
                 * were being kept out of a conversation they are running.
                 *
                 * Spelled exactly as `Wishlist::allowsContributionsFrom()`
                 * spells it, deliberately. That method solved this same problem
                 * for the pot, and a board and a pot are visible to the same
                 * people for the same reason — a second phrasing of one rule is
                 * the thing that drifts.
                 */
                $list->kind->ownerSeesContributions()
                || ! $list->shouldHideClaimsFrom($viewer)
            );
    }

    /**
     * May this viewer post?
     *
     * Reading and writing are the same right here, plus an identity to own the
     * row: a message belongs to somebody, and there has to be a somebody to own
     * it and to take it back later. The same `exists()` check the vote and the
     * pledge make, and for the same reason — without it a visitor whose cookie
     * identity has not been minted yet is shown a form that 403s on submit.
     */
    public function writableBy(Wishlist $list, Owner $viewer): bool
    {
        return $viewer->exists() && $this->visibleTo($list, $viewer);
    }

    /**
     * The board, as the page renders it. Null when this viewer has none.
     *
     * Null rather than an empty list, so a page draws no rail at all rather
     * than an empty one — the same discipline `ContributionView` follows for
     * the pot.
     *
     * @return array<string, mixed>|null
     */
    public function forList(Wishlist $list, Owner $viewer): ?array
    {
        if (! $this->visibleTo($list, $viewer)) {
            return null;
        }

        /** @var Collection<int, ListMessage> $messages */
        $messages = $list->messages()
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        return [
            'canPost' => $this->writableBy($list, $viewer),
            'messages' => $messages
                ->map(fn (ListMessage $message): array => $this->present($message, $list, $viewer))
                ->values()
                ->all(),
        ];
    }

    /**
     * One message, as the client sees it.
     *
     * Public because the POST endpoint answers with the row it just created —
     * the board posts over `fetch` and appends the reply rather than reloading
     * the page, and a message shaped one way here and another way there is two
     * shapes to keep in step.
     *
     * @return array<string, mixed>
     */
    public function present(ListMessage $message, Wishlist $list, Owner $viewer): array
    {
        return [
            'id' => $message->id,
            'name' => $message->display_name,
            'body' => $message->body,
            'at' => $message->created_at?->toIso8601String(),
            /*
             * Whether to offer the delete control — never who wrote it.
             *
             * The identity columns stay on the server: a name is what the board
             * shows, and `user_id` beside a message about what somebody has
             * bought is claim state with a person attached. The endpoint asks
             * this again before it deletes, because hiding a control stops
             * nobody hand-building the request.
             */
            'mine' => $message->wasWrittenBy($viewer),
            'removable' => $message->wasWrittenBy($viewer) || $list->isOwnedBy($viewer),
        ];
    }
}
