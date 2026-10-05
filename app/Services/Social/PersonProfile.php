<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\Interest;
use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\TasteSource;
use App\Models\Friendship;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Support\CurrentMarket;
use App\Support\DayAndMonth;
use Illuminate\Support\Collection;

/**
 * A saved person's page as a profile: what you know about them, their own
 * wish lists, and the lists you are making for them.
 *
 * Until 2026-09-27 `/people/{id}` was titled "Cadeaus voor Mama" and showed a
 * gift history and two buttons. Everything the site knew about her (her
 * interests, style, budget, what to avoid, her birthday) was stored on the
 * recipient and could only be read or changed from inside Find a gift, and
 * none of her lists were on her page. The owner asked for a page about the
 * person; this is what it reads.
 *
 * ## What may appear, and why
 *
 * - **What you know** is your own record (`recipients`, owner-scoped by the
 *   controller before it gets here). Taste may have been written by the person
 *   themselves through their `/for/{token}` link: `taste_source` says which,
 *   and the page says it in plain words.
 * - **Their wish lists** only when the saved person is linked to an account
 *   that is still your friend, and only what `MyPeople::sharedWith()` already
 *   shows on My people: lists they shared with you, sent you the link to, or
 *   show to all their people. Kind `mine` only: a list they are making for
 *   somebody else is not their wish list.
 * - **Lists for them** are yours: kind `for_someone` or `group`, about this
 *   person, owned by you.
 * - **Together** ("Samen met"), for a friend only: {@see InCommon}, which
 *   holds the privacy rules for it.
 *
 * No claim state (invariant 4): no list's items are loaded.
 */
class PersonProfile
{
    public function __construct(
        private readonly MyPeople $people,
        private readonly Friends $friends,
        private readonly InCommon $inCommon,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $viewer, Recipient $person, CurrentMarket $current): array
    {
        $friendship = $this->friendshipWith($viewer, $person);

        // The date you saved comes first, as on My people: it is what the
        // reminder email reads. Theirs, when they share it, fills a gap.
        $birthday = DayAndMonth::fromDate($person->birthday)
            ?? ($friendship === null ? null : $this->people->birthdayFor($friendship));

        // Their lists that reached you: their wish lists feed one section,
        // the lists they make for others another ("Samen met").
        $shared = $friendship === null ? collect() : $this->people->sharedWith($viewer, collect([$friendship]));

        return [
            'relationshipLabel' => $this->people->relationshipLabel($person->relationship),
            'birthday' => $birthday?->toString(),
            'isFriend' => $friendship !== null,
            // The "op GiftCoves" pill beside the name: a friend, or a saved person
            // an account stands behind (owner, 2026-10-05; it showed for friends only).
            'onGiftCoves' => $friendship !== null || $person->isLinked(),
            // Their account's id, for "Remove as friend" in the page's ⋯ menu
            // (DELETE /friends/{id}, the same request as on My people).
            'friendId' => $friendship === null ? null : (int) $friendship->friend_id,
            'about' => $this->about($person),
            'theirLists' => $friendship === null ? [] : $this->theirWishLists($shared[$friendship->friend_id] ?? collect(), $current),
            'listsForThem' => $this->listsForThem($viewer, $person, $current),
            // Friends only: somebody without an account cannot be matched to a
            // group gift or a Secret Santa. See InCommon for what may show.
            'together' => $friendship === null
                ? null
                : $this->inCommon->for($viewer, [(int) $friendship->friend_id], $shared, $current)[(int) $friendship->friend_id],
        ];
    }

    /**
     * What you know, raw values and all: the page edits them in place with
     * Find a gift's own vocabularies, so it needs the value, not only a label.
     *
     * @return array<string, mixed>
     */
    private function about(Recipient $person): array
    {
        $interests = array_values(array_filter((array) $person->interests, fn ($v) => is_string($v) && trim($v) !== ''));

        return [
            'interests' => array_map(fn (string $value) => [
                'value' => $value,
                'label' => Interest::tryFrom($value)?->label() ?? $value,
            ], $interests),
            'ageBand' => $person->age_band,
            'gender' => $person->gender,
            // The taste pairs (Handig|Design, Modern|Vintage...), the sides chosen;
            // shown in About since 2026-10-05 (owner: "and the vibes?").
            'preferences' => array_values(array_filter((array) $person->preferences, 'is_string')),
            'avoid' => array_values((array) $person->avoid),
            /*
             * Who last described their taste. Two values only, and "guessed
             * from This or that" is not one of them: a game you played for
             * them is stored as your guess (TasteSource::Suggested), exactly
             * like typing it. So the page can say "from you" or "from them",
             * and nothing finer without a new column.
             */
            'tasteSource' => $person->taste_source instanceof TasteSource ? $person->taste_source->value : null,
        ];
    }

    /**
     * The friendship behind this saved person, if they are linked to an
     * account that is still your friend. A link to somebody who removed you
     * shows nothing of theirs: removing a friend is how a person takes their
     * lists back.
     */
    private function friendshipWith(User $viewer, Recipient $person): ?Friendship
    {
        if ($person->user_id === null) {
            return null;
        }

        // The one row, not every friend loaded to pick it out. With the
        // friend, as `Friends::forUser()` hands its rows over.
        return Friendship::query()
            ->with('friend')
            ->where('user_id', $viewer->id)
            ->where('friend_id', $person->user_id)
            ->first();
    }

    /**
     * @param  Collection<int, Wishlist>  $lists  their lists that reached you
     * @return list<array{id: string, title: string, kind: string, url: string, eventDate: string|null}>
     */
    private function theirWishLists(Collection $lists, CurrentMarket $current): array
    {
        return $lists
            ->filter(fn (Wishlist $list) => $list->kind === ListKind::Mine)
            ->sortByDesc('created_at')
            ->map(fn (Wishlist $list) => [
                'id' => $list->id,
                'title' => $list->displayTitle(),
                'kind' => $list->kind->value,
                // The share link: the only page of theirs a visitor may open.
                'url' => $current->url("l/{$list->share_token}"),
                'eventDate' => $list->event_date?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Your lists for them, in the shape of a row on Mijn Coves (`summarise()`
     * plus the overview's covers): since 2026-09-27 both pages draw the same
     * row (`ListSummaryRow`), with the pictures, the count, the pills, and
     * add, share and the same ⋯. Only what that row reads. The share link is
     * given because these are your own lists.
     *
     * @return list<array<string, mixed>>
     */
    private function listsForThem(User $viewer, Recipient $person, CurrentMarket $current): array
    {
        return Wishlist::query()
            ->where('owner_user_id', $viewer->id)
            ->where('recipient_id', $person->id)
            ->whereIn('kind', [ListKind::ForSomeone->value, ListKind::Group->value])
            ->with([
                // As on Mijn Coves: four stored pictures, which leaves Amazon
                // out (invariant 6).
                'items' => fn ($q) => $q->whereNotNull('snapshot_image_url')->latest('created_at')->limit(4),
            ])
            ->withCount(['items', 'suggestions'])
            ->latest()
            ->get()
            ->map(fn (Wishlist $list) => [
                'id' => $list->id,
                'title' => $list->displayTitle(),
                'kind' => $list->kind->value,
                // Your own page of it, where you can edit it.
                'url' => $current->url("lists/{$list->id}"),
                'eventDate' => $list->event_date?->toDateString(),
                'isDefault' => (bool) $list->is_default,
                'visibility' => $list->visibility->value,
                // Published as a Community Cove: the row's "Privé" reads it too.
                'published' => $list->isCommunityCove(),
                'itemCount' => (int) $list->items_count,
                'covers' => $list->items->pluck('snapshot_image_url')->all(),
                'recipient' => ['id' => $person->id, 'name' => $person->name],
                'suggestions' => (int) $list->suggestions_count,
                'sharedWithMe' => false,
                'linkCanAdd' => $list->linkCanAdd(),
                'ownerName' => null,
                'role' => null,
                'shareUrl' => $list->visibility === ListVisibility::Private
                    ? null
                    : url($current->url("l/{$list->share_token}")),
                // Only a wish list of your own can be shown to your people.
                'visibleToFriends' => null,
                'pledgersVisible' => $list->pledgersVisible(),
                'votingEnabled' => $list->votingEnabled(),
            ])
            ->values()
            ->all();
    }
}
