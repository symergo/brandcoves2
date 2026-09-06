<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Mail\ListInvitationMail;
use App\Models\Friendship;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistShare;
use App\Services\Notifications\ListActivity;
use App\Support\CurrentMarket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * "Share with friends" — pick names, they get an email and the list on their page.
 *
 * ## Why picking names and not a switch
 *
 * There was a `show_to_friends` boolean for a day, meaning *everybody I am
 * connected to sees this*. A friendship here is made by opening any share link,
 * so that switch published to a set the owner had never chosen and could not
 * see: one tap on a box reading "my friends can see this" put a list in front
 * of a group whose membership had accumulated by accident. A boolean cannot
 * express consent to an audience. A row per person can.
 *
 * ## Only friends, and only your own list
 *
 * Both checked here rather than trusted from the form. The friend check is what
 * stops this becoming a way to put an arbitrary list on an arbitrary stranger's
 * page — and, since the picker only ever offers friends, an id that is not one
 * is a hand-built request and is dropped in silence rather than argued with.
 *
 * ## The email carries no products
 *
 * {@see ListInvitationMail} was written for the invitations feature that was
 * removed, and its discipline is exactly what is wanted here: a title, who is
 * asking, and a link. A list can be private research about a third person, and
 * mailing its contents would publish that research to whoever holds the inbox.
 *
 * ## Failure is per person
 *
 * The share row is written first and the mail is attempted after, one at a
 * time. A bounced address must not cost the other four their share — and the
 * share is the part that lasts, while the mail is a nudge that the friends page
 * makes redundant the moment they next open it.
 */
class ListSharer
{
    public function __construct(private readonly ListActivity $activity) {}

    /**
     * Share a list with some of the owner's friends.
     *
     * Idempotent: sharing again with the same person updates nothing and sends
     * no second email, because "share" is a state rather than an event and a
     * duplicate press is the commonest way to send somebody two of the same
     * message.
     *
     * @param  list<int>  $friendIds
     * @return int how many people it actually reached, for the confirmation
     */
    public function share(Wishlist $list, User $owner, array $friendIds, CurrentMarket $current): int
    {
        if (! $list->visibility->isShareable()) {
            // The picker is hidden on a private list; a hand-built request gets
            // nothing. Sharing something nobody can open is not a kindness.
            return 0;
        }

        $friends = $this->friendsAmong($owner, $friendIds);

        if ($friends->isEmpty()) {
            return 0;
        }

        // Who already had it, so the mail goes only to the new names.
        $already = WishlistShare::query()
            ->where('wishlist_id', $list->id)
            ->whereIn('user_id', $friends->modelKeys())
            ->pluck('user_id')
            ->all();

        $new = $friends->reject(fn (User $friend) => in_array($friend->id, $already, true));

        if ($new->isEmpty()) {
            return 0;
        }

        $now = now();

        WishlistShare::query()->insert($new->map(fn (User $friend) => [
            'wishlist_id' => $list->id,
            'user_id' => $friend->id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        foreach ($new as $friend) {
            $this->notify($list, $owner, $friend, $current);

            /*
             * And in the inbox, which is the half that survives.
             *
             * The email is a nudge somebody may never open; the notification is
             * there next time they are on the site, beside every other thing
             * that happened while they were away. Written after the share row
             * and never instead of it.
             */
            $this->activity->shared($list, $owner, $friend, url($current->url("l/{$list->share_token}")));
        }

        return $new->count();
    }

    /** Take the list off somebody's friends page. Their link, if they have one, still works. */
    public function unshare(Wishlist $list, int $friendId): void
    {
        WishlistShare::query()
            ->where('wishlist_id', $list->id)
            ->where('user_id', $friendId)
            ->delete();
    }

    /**
     * The subset of those ids that are actually this person's friends.
     *
     * One query, and it reads `friendships` rather than trusting the form: the
     * picker offers only friends, so anything else arrived by hand.
     *
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function friendsAmong(User $owner, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $friendIds = Friendship::query()
            ->where('user_id', $owner->id)
            ->whereIn('friend_id', $ids)
            ->pluck('friend_id');

        return User::query()->whereIn('id', $friendIds)->get();
    }

    /**
     * The nudge, sent one at a time and never allowed to fail the share.
     *
     * `Mail::to()->queue()` rather than `send()`: this runs inside a request
     * that has just been told the sharing worked, and five SMTP round trips in
     * the foreground is how a form starts timing out for the person who picked
     * the most friends.
     */
    private function notify(Wishlist $list, User $owner, User $friend, CurrentMarket $current): void
    {
        try {
            Mail::to($friend->email)->queue(new ListInvitationMail(
                listTitle: $list->displayTitle(),
                fromName: $owner->displayName(),
                market: $current->get(),
                url: url($current->url("l/{$list->share_token}")),
                // Only when the list is about somebody, which is what the mail
                // template asks for. A wish list is about its owner and the
                // copy already names them.
                forName: $list->isForSomeoneElse() ? $list->recipient?->name : null,
            ));
        } catch (\Throwable $e) {
            /*
             * The share stands whatever the mail does.
             *
             * A bad address, a full queue or a misconfigured mailer must not
             * undo a decision the owner has already made and been told about —
             * and the friends page shows the list the moment they next open it,
             * which is what makes the email a convenience rather than the
             * mechanism.
             */
            Log::warning('Sharing a list could not be emailed', [
                'wishlist_id' => $list->id,
                'user_id' => $friend->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
