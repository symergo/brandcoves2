<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\ListKind;
use App\Models\GiftPledge;
use App\Models\SecretSantaGroup;
use App\Models\SecretSantaMember;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use App\Support\CurrentMarket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Samen met {naam}": what you and a friend are doing together.
 *
 * Three things, each for friends on GiftCoves only (somebody with no account
 * cannot be matched to anything):
 *
 * - **Lists they are making for somebody else** that reached you (shared with
 *   you, or you opened the link), labelled with who they are for. Until
 *   2026-09-27 these sat among "their lists", beside their own wish lists,
 *   which read as if the friend wanted the things on a list for their
 *   grandfather.
 * - **Group gifts you both take part in**, with the friend's part in it.
 * - **Secret Santa groups you are both in.**
 *
 * ## Privacy, which is most of this class
 *
 * a. **Never a list about you.** A gift list or group gift whose recipient is
 *    linked to your account is left out everywhere, even when its link
 *    reached you (`Wishlist::notAbout()`): it is your own surprise.
 * b. **A friend's part in a group gift only when the organiser lets names be
 *    seen** (`Wishlist::pledgersVisible()`, the organiser's choice since
 *    2026-09-01). Without it, "Sam contributes" would tell you something the
 *    list itself does not, so the group gift shows only when the friend is its
 *    organiser, which everybody on the list sees anyway. The one exception is
 *    a group gift you organise yourself: its page already names every
 *    contributor to you (ContributionView gives the organiser the breakdown).
 *    Collaborators count as taking part and fall under the same rule.
 *    Amounts never appear.
 * c. **Secret Santa: shared membership only.** Never who drew whom, not even
 *    "you drew them" (the owner's default for now; he can choose to show that
 *    later). The pairing column (`assigned_member_id`) is encrypted and
 *    `$hidden`, and is never selected here.
 * d. **No claim state** (invariant 4): no list's items are loaded.
 */
class InCommon
{
    /**
     * What you share with each of these friends, by friend id.
     *
     * @param  list<int>  $friendIds
     * @param  Collection<int, Collection<int, Wishlist>>  $sharedLists  their lists that reached you, by owner (MyPeople::sharedWith())
     * @return array<int, array{lists: list<array<string, mixed>>, groups: list<array<string, mixed>>, santa: list<array<string, mixed>>}>
     */
    public function for(User $me, array $friendIds, Collection $sharedLists, CurrentMarket $current): array
    {
        $out = [];

        if ($friendIds === []) {
            return $out;
        }

        $groups = $this->groupGifts($me, $friendIds, $current);
        $santa = $this->santa($me, $friendIds);

        foreach ($friendIds as $friendId) {
            $groupIds = array_column($groups[$friendId] ?? [], 'id');

            /** @var Collection<int, Wishlist> $theirs */
            $theirs = $sharedLists[$friendId] ?? collect();

            $out[$friendId] = [
                'lists' => $theirs
                    ->filter(fn (Wishlist $list) => $list->kind->isForSomeoneElse())
                    // Shown once, under group gifts, when it is one you are in.
                    ->reject(fn (Wishlist $list) => in_array($list->id, $groupIds, true))
                    ->sortByDesc('created_at')
                    ->map(fn (Wishlist $list) => [
                        'id' => $list->id,
                        'title' => $list->displayTitle(),
                        'kind' => $list->kind->value,
                        // The recipient's name is on the shared list's own
                        // page for anybody who may open it, so not new here.
                        'forName' => $list->recipient?->name,
                        'url' => $current->url("l/{$list->share_token}"),
                        'eventDate' => $list->event_date?->toDateString(),
                    ])
                    ->values()
                    ->all(),
                'groups' => $groups[$friendId] ?? [],
                'santa' => $santa[$friendId] ?? [],
            ];
        }

        return $out;
    }

    /**
     * The upcoming Secret Santa you are both in, for the small mark on My
     * people: the nearest one with a date still ahead, or one with no date
     * yet. A group whose day has passed is history, and marking a friend for
     * last year's office draw would read as news.
     *
     * @param  list<array{id: string, title: string, date: string|null, url: string}>  $santa
     * @return array{title: string, date: string|null}|null
     */
    public function nextSanta(array $santa, ?CarbonImmutable $today = null): ?array
    {
        $today = ($today ?? CarbonImmutable::today())->toDateString();

        $ahead = array_values(array_filter($santa, fn (array $group) => $group['date'] === null || $group['date'] >= $today));

        usort($ahead, fn (array $a, array $b) => strcmp($a['date'] ?? '9999', $b['date'] ?? '9999'));

        return $ahead === [] ? null : ['title' => $ahead[0]['title'], 'date' => $ahead[0]['date']];
    }

    /**
     * Group gifts you take part in, per friend who takes part too.
     *
     * Taking part: organising it (owning the list), having put money in
     * (`gift_pledges.user_id`), or helping run it (`wishlist_collaborators`).
     *
     * @param  list<int>  $friendIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupGifts(User $me, array $friendIds, CurrentMarket $current): array
    {
        $lists = Wishlist::query()
            ->where('kind', ListKind::Group->value)
            ->where(fn ($in) => $in
                ->where('owner_user_id', $me->id)
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('gift_pledges')
                    ->whereColumn('gift_pledges.wishlist_id', 'wishlists.id')
                    ->where('gift_pledges.user_id', $me->id))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('wishlist_collaborators')
                    ->whereColumn('wishlist_collaborators.wishlist_id', 'wishlists.id')
                    ->where('wishlist_collaborators.user_id', $me->id)))
            // (a) Never a group gift for you.
            ->notAbout($me)
            ->with('recipient:id,name')
            ->get();

        if ($lists->isEmpty()) {
            return [];
        }

        // Who else is in, as ids only: never an amount.
        $pledgers = GiftPledge::query()
            ->whereIn('wishlist_id', $lists->modelKeys())
            ->whereIn('user_id', $friendIds)
            ->get(['wishlist_id', 'user_id'])
            ->groupBy('wishlist_id')
            ->map(fn (Collection $rows) => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        $helpers = WishlistCollaborator::query()
            ->whereIn('wishlist_id', $lists->modelKeys())
            ->whereIn('user_id', $friendIds)
            ->get(['wishlist_id', 'user_id'])
            ->groupBy('wishlist_id')
            ->map(fn (Collection $rows) => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        $out = [];

        foreach ($lists as $list) {
            $iOrganise = $list->owner_user_id === $me->id;
            $in = array_unique([...$pledgers[$list->id] ?? [], ...$helpers[$list->id] ?? []]);

            foreach ($friendIds as $friendId) {
                if ($list->owner_user_id === $friendId) {
                    $role = 'organiser';
                } elseif (in_array($friendId, $in, true) && ($list->pledgersVisible() || $iOrganise)) {
                    // (b) Named only where the list itself would name them to you.
                    $role = 'contributes';
                } else {
                    continue;
                }

                $out[$friendId][] = [
                    'id' => $list->id,
                    'title' => $list->displayTitle(),
                    'kind' => $list->kind->value,
                    'forName' => $list->recipient?->name,
                    'role' => $role,
                    // Yours to edit when you organise it; otherwise the link
                    // you took part through.
                    'url' => $iOrganise ? $current->url("lists/{$list->id}") : $current->url("l/{$list->share_token}"),
                    'eventDate' => $list->event_date?->toDateString(),
                ];
            }
        }

        return $out;
    }

    /**
     * Secret Santa groups you and each friend are both in, as organiser or
     * member. Members who left (`removed_at`) do not count, and members without
     * an account cannot be matched to a friend at all.
     *
     * @param  list<int>  $friendIds
     * @return array<int, list<array{id: string, title: string, date: string|null, url: string}>>
     */
    private function santa(User $me, array $friendIds): array
    {
        $groups = SecretSantaGroup::query()
            ->where(fn ($in) => $in
                ->where('owner_user_id', $me->id)
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('secret_santa_members')
                    ->whereColumn('secret_santa_members.group_id', 'secret_santa_groups.id')
                    ->where('secret_santa_members.user_id', $me->id)
                    ->whereNull('secret_santa_members.removed_at')))
            ->get(['id', 'title', 'market', 'exchange_date', 'owner_user_id', 'created_at']);

        if ($groups->isEmpty()) {
            return [];
        }

        // (c) Only who is in. `assigned_member_id`, the draw, is not selected.
        $members = SecretSantaMember::query()
            ->whereIn('group_id', $groups->modelKeys())
            ->whereNull('removed_at')
            ->whereIn('user_id', $friendIds)
            ->get(['group_id', 'user_id'])
            ->groupBy('group_id')
            ->map(fn (Collection $rows) => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        $out = [];

        foreach ($groups->sortByDesc(fn (SecretSantaGroup $g) => $g->exchange_date?->toDateString() ?? '9999') as $group) {
            $in = [...$members[$group->id] ?? [], (int) $group->owner_user_id];

            foreach ($friendIds as $friendId) {
                if (! in_array($friendId, $in, true)) {
                    continue;
                }

                $out[$friendId][] = [
                    'id' => $group->id,
                    'title' => $group->title,
                    'date' => $group->exchange_date?->toDateString(),
                    // The group's own market: that is where its page lives.
                    'url' => '/'.$group->market->value."/santa/{$group->id}",
                ];
            }
        }

        return $out;
    }
}
