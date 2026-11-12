<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\SantaStatus;
use App\Mail\SantaNoteMail;
use App\Mail\SecretSantaAssignmentMail;
use App\Models\SecretSantaGroup;
use App\Models\SecretSantaMember;
use App\Models\SecretSantaQuestion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Number;

/**
 * A Secret Friend group, played: the draw and everything around it that has
 * to work without a request (a scheduled draw, the weekly mail, next year).
 *
 * `SecretSantaDraw` and `SantaRepair` stay pure, ids in and ids out. This is
 * the one place that reads members, writes assignments and sends mail, so the
 * organiser's button, a draw that happens by itself and a late joiner cannot
 * each grow their own version of it.
 *
 * See docs/features/secret-santa.md, "The game around the draw".
 */
class SantaGame
{
    /**
     * What somebody can tick about themselves in a few seconds. Keys only; the
     * words are `site.santa.interest.*`, which double as the search term for
     * "ideas within budget".
     *
     * @var list<string>
     */
    public const INTERESTS = [
        'books', 'cooking', 'coffee', 'drinks', 'sweets', 'games', 'sport', 'outdoor', 'music',
        'film', 'tech', 'home', 'garden', 'beauty', 'fashion', 'diy', 'pets', 'travel',
    ];

    public function __construct(
        private readonly SecretSantaDraw $draw,
        private readonly SantaRepair $repair,
    ) {}

    /**
     * Do the draw and mail everybody.
     *
     * In a transaction, because a partial draw is the worst state there is:
     * some people know who they have, the rest do not.
     *
     * @throws DrawImpossible
     */
    public function draw(SecretSantaGroup $santa): void
    {
        // Copied from last year and never said they are in again: they sit out.
        $santa->members()->whereNull('joined_at')->update(['removed_at' => now()]);

        $members = $santa->members()->get();
        $ids = $members->pluck('id')->all();
        $exclusions = $this->exclusionMap($members);
        $lastYear = $this->lastYear($santa, $members);

        try {
            $assignments = $this->draw->assign($ids, $this->merged($exclusions, $lastYear));
        } catch (DrawImpossible $e) {
            // "Not who you had last year" is a nicety. It never blocks a draw
            // that the members' own rules allow.
            if ($lastYear === []) {
                throw $e;
            }

            $assignments = $this->draw->assign($ids, $exclusions);
        }

        DB::transaction(function () use ($santa, $members, $assignments): void {
            foreach ($members as $member) {
                $member->update(['assigned_member_id' => (string) $assignments[$member->id], 'revealed_at' => null]);
            }

            $santa->update(['status' => SantaStatus::Drawn, 'drawn_at' => now(), 'draw_at' => null]);
        });

        // After the commit: mail queued inside it would go out even if the
        // write rolled back, and a sent mail cannot be unsent.
        $this->notify($santa, $santa->members()->get());
    }

    /**
     * Somebody joined after the draw: one giver hands over their giftee.
     *
     * @throws DrawImpossible when nobody can be asked to change any more
     */
    public function lateJoin(SecretSantaGroup $santa, SecretSantaMember $joining): void
    {
        $members = $santa->members()->get();
        $assignments = $this->assignments($members);

        // Whoever already bought keeps their giftee: the gift is for them.
        $eligible = $members
            ->filter(fn (SecretSantaMember $m) => $m->id !== $joining->id && $m->marked_done_at === null && $m->assigned_member_id !== null)
            ->pluck('id')
            ->all();

        $changed = $this->repair->insert($assignments, $joining->id, $eligible, $this->exclusionMap($members));

        DB::transaction(function () use ($members, $changed): void {
            foreach ($changed as $giverId => $gifteeId) {
                $members->firstWhere('id', $giverId)?->update(['assigned_member_id' => (string) $gifteeId, 'revealed_at' => null]);
            }
        });

        $fresh = $santa->members()->whereIn('id', array_keys($changed))->get();

        $this->notify($santa, $fresh->where('id', '!==', $joining->id), changed: true);
        $this->notify($santa, $fresh->where('id', $joining->id));
    }

    /**
     * One mail per member, about their own assignment.
     *
     * The mail no longer says the name (2026-11-12): it says there is one, and
     * the member's page turns the card. `gifteeName` still travels with the
     * mail for the page it links to and for the tests that prove who got whom.
     *
     * @param  iterable<SecretSantaMember>  $members
     */
    public function notify(SecretSantaGroup $santa, iterable $members, bool $changed = false): void
    {
        // Every member, removed ones included, so a pairing that points at
        // somebody who left still resolves to a name.
        $byId = $santa->allMembers()->get()->keyBy('id');

        foreach ($members as $member) {
            $giftee = $byId->get((int) $member->assigned_member_id);

            if ($giftee === null) {
                continue;
            }

            Mail::to($member->email)->queue(new SecretSantaAssignmentMail(
                gifteeName: $giftee->display_name,
                groupTitle: $santa->title,
                market: $santa->market,
                meUrl: url($santa->path("me/{$member->join_token}")),
                budget: $this->budget($santa),
                exchangeDate: $santa->exchange_date?->locale($santa->market->language())->translatedFormat('j F Y'),
                gifteeHasList: $giftee->wishlist_id !== null,
                changed: $changed,
            ));
        }
    }

    public function budget(SecretSantaGroup $santa): ?string
    {
        return $santa->budget_max === null ? null : Number::currency(
            $santa->budget_max / 100,
            $santa->market->currency(),
            $santa->market->hrefLang(),
            $santa->budget_max % 100 === 0 ? 0 : 2,
        );
    }

    /**
     * A short mail to one member, unless they muted these.
     *
     * @param  list<string>  $lines
     */
    public function note(SecretSantaGroup $santa, SecretSantaMember $member, string $kind, string $subject, array $lines, string $cta): void
    {
        if ($member->muted_at !== null || $member->removed_at !== null || blank($member->email)) {
            return;
        }

        Mail::to($member->email)->queue(new SantaNoteMail(
            kind: $kind,
            subjectLine: $subject,
            groupTitle: $santa->title,
            lines: array_values(array_filter($lines)),
            cta: $cta,
            url: url($santa->path("me/{$member->join_token}")),
            market: $santa->market,
            // The foot of their own page, where the button is.
            muteUrl: url($santa->path("me/{$member->join_token}")).'#mails',
        ));
    }

    /**
     * giver => giftee, for everybody who has one.
     *
     * @param  Collection<int, SecretSantaMember>  $members
     * @return array<int, int>
     */
    public function assignments(Collection $members): array
    {
        $assignments = [];

        foreach ($members as $member) {
            if ($member->assigned_member_id !== null) {
                $assignments[$member->id] = (int) $member->assigned_member_id;
            }
        }

        return $assignments;
    }

    /** Who drew this member, or null before the draw. */
    public function santaOf(SecretSantaGroup $santa, SecretSantaMember $member): ?SecretSantaMember
    {
        // The pairing is encrypted, so this is a scan of one small group, not a query.
        return $santa->members()->get()
            ->first(fn (SecretSantaMember $other) => $other->assigned_member_id !== null && (int) $other->assigned_member_id === $member->id);
    }

    /**
     * Each member's exclusions as ids. A member names people by name or by
     * address; the organiser's couples are stored as addresses.
     *
     * @param  Collection<int, SecretSantaMember>  $members
     * @return array<int, list<int>>
     */
    public function exclusionMap(Collection $members): array
    {
        $map = [];

        foreach ($members as $member) {
            $excluded = array_map(mb_strtolower(...), (array) $member->exclusions);

            $map[$member->id] = $members
                ->filter(fn (SecretSantaMember $other) => $other->id !== $member->id && (
                    in_array(mb_strtolower((string) $other->email), $excluded, true)
                    || in_array(mb_strtolower((string) $other->display_name), $excluded, true)
                ))
                ->pluck('id')
                ->values()
                ->all();
        }

        return $map;
    }

    /**
     * Aggregate progress: never who.
     *
     * @return array{members: int, ready: int, done: int}
     */
    public function progress(SecretSantaGroup $santa): array
    {
        $members = $santa->members()->get();

        return [
            'members' => $members->count(),
            'ready' => $members->filter(fn (SecretSantaMember $m) => $this->hasSomethingToGoOn($m))->count(),
            'done' => $members->whereNotNull('marked_done_at')->count(),
        ];
    }

    /** A list, ticked interests or a written hint: their Santa is not shopping blind. */
    public function hasSomethingToGoOn(SecretSantaMember $member): bool
    {
        return $member->wishlist_id !== null || (array) $member->interests !== [] || filled($member->hints);
    }

    /**
     * Draw now if the group said "once this many are in" and they are.
     */
    public function drawIfFull(SecretSantaGroup $santa): bool
    {
        if ($santa->status->isDrawn() || $santa->draw_when_count === null) {
            return false;
        }

        if ($santa->members()->whereNotNull('joined_at')->count() < $santa->draw_when_count) {
            return false;
        }

        try {
            $this->draw($santa);
        } catch (DrawImpossible) {
            // The organiser's page still has the button, which names the blocker.
            return false;
        }

        return true;
    }

    /**
     * Groups whose draw moment has come. Run every few minutes.
     *
     * @return int how many were drawn
     */
    public function drawDue(): int
    {
        $drawn = 0;

        SecretSantaGroup::query()
            ->where('status', SantaStatus::Open->value)
            ->whereNotNull('draw_at')
            ->where('draw_at', '<=', now())
            ->get()
            ->each(function (SecretSantaGroup $santa) use (&$drawn): void {
                try {
                    $this->draw($santa);
                    $drawn++;
                } catch (DrawImpossible $e) {
                    // Once, not every five minutes: the moment is cleared and
                    // the organiser is told why, so they can fix it and press
                    // the button themselves.
                    $santa->update(['draw_at' => null]);
                    Log::info('Secret Friend: scheduled draw impossible', ['group' => $santa->id, 'reason' => $e->getMessage()]);

                    $organiser = $santa->members()->where('user_id', $santa->owner_user_id)->first();
                    $language = $santa->market->language();

                    if ($organiser !== null) {
                        $this->note($santa, $organiser, 'draw_failed',
                            __('site.santa.mail.draw_failed_subject', ['title' => $santa->title], $language),
                            [__('site.santa.mail.draw_failed_body', [], $language), $e->getMessage()],
                            __('site.santa.mail.open_group', [], $language),
                        );
                    }
                }
            });

        return $drawn;
    }

    /**
     * The weekly mail: how far the group is, and the one thing this member
     * could still do. Nobody with nothing left to do is mailed.
     *
     * @return int how many mails were queued
     */
    public function sendProgress(): int
    {
        $sent = 0;

        SecretSantaGroup::query()
            ->where(fn ($q) => $q
                ->whereDate('exchange_date', '>=', now()->toDateString())
                ->orWhere(fn ($q) => $q->whereNull('exchange_date')->where('created_at', '>=', now()->subDays(60))))
            ->get()
            ->each(function (SecretSantaGroup $santa) use (&$sent): void {
                $language = $santa->market->language();
                $progress = $this->progress($santa);

                if ($progress['members'] < 2) {
                    return;
                }

                $drawn = $santa->status->isDrawn();
                $days = $santa->exchange_date === null ? null : (int) now()->startOfDay()->diffInDays($santa->exchange_date->startOfDay());

                $waiting = SecretSantaQuestion::query()
                    ->where('group_id', $santa->id)
                    ->whereNull('answered_at')
                    ->pluck('giftee_member_id')
                    ->countBy();

                foreach ($santa->members()->get() as $member) {
                    $todo = match (true) {
                        ($waiting[$member->id] ?? 0) > 0 => __('site.santa.mail.todo_answer', [], $language),
                        ! $this->hasSomethingToGoOn($member) => __('site.santa.mail.todo_list', [], $language),
                        $drawn && $member->assigned_member_id !== null && $member->revealed_at === null => __('site.santa.mail.todo_reveal', [], $language),
                        $drawn && $member->assigned_member_id !== null && $member->marked_done_at === null => __('site.santa.mail.todo_buy', [], $language),
                        default => null,
                    };

                    if ($todo === null) {
                        continue;
                    }

                    $this->note($santa, $member, 'progress',
                        __('site.santa.mail.progress_subject', ['title' => $santa->title], $language),
                        [
                            $drawn
                                ? __('site.santa.progress_done', ['done' => $progress['done'], 'total' => $progress['members']], $language)
                                : __('site.santa.progress_ready', ['ready' => $progress['ready'], 'total' => $progress['members']], $language),
                            $days === null ? null : __(match (true) {
                                $days <= 0 => 'site.santa.exchange_today',
                                $days === 1 => 'site.santa.exchange_in_one',
                                default => 'site.santa.exchange_in_days',
                            }, ['days' => $days], $language),
                            $todo,
                        ],
                        __('site.santa.mail.open_mine', [], $language),
                    );
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * Early November: last season's organisers are asked to go again.
     *
     * @return int how many organisers were mailed
     */
    public function sendYearly(): int
    {
        $sent = 0;

        SecretSantaGroup::query()
            ->where('status', SantaStatus::Drawn->value)
            ->whereBetween('exchange_date', [now()->subMonths(13)->toDateString(), now()->subMonths(8)->toDateString()])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('secret_santa_groups as next')->whereColumn('next.previous_group_id', 'secret_santa_groups.id'))
            ->with('organiser')
            ->get()
            ->each(function (SecretSantaGroup $santa) use (&$sent): void {
                $organiser = $santa->organiser;

                if (! $organiser instanceof User || blank($organiser->email) || ! $organiser->emailConfirmed()) {
                    return;
                }

                $language = $santa->market->language();

                Mail::to($organiser->email)->queue(new SantaNoteMail(
                    kind: 'yearly',
                    subjectLine: __('site.santa.mail.yearly_subject', ['title' => $santa->title], $language),
                    groupTitle: $santa->title,
                    lines: [__('site.santa.mail.yearly_body', ['count' => $santa->members()->count()], $language)],
                    cta: __('site.santa.again', [], $language),
                    url: url($santa->path()),
                    market: $santa->market,
                ));
                $sent++;
            });

        return $sent;
    }

    /**
     * The same group, a year on: same people, same rules, a fresh draw.
     *
     * Everybody is copied in but only the organiser counts as joined; the
     * others say "I'm in" with one press on the link they are mailed. Whoever
     * does not is left out of the draw.
     */
    public function again(SecretSantaGroup $santa): SecretSantaGroup
    {
        $next = $santa->exchange_date?->copy();

        while ($next !== null && $next->isPast()) {
            $next->addYear();
        }

        $year = (string) ($next?->year ?? now()->year);

        $new = SecretSantaGroup::create([
            'owner_user_id' => $santa->owner_user_id,
            'market' => $santa->market,
            // "Office 2025" becomes "Office 2026"; a title without a year is kept.
            'title' => preg_replace('/\b20\d{2}\b/', $year, $santa->title) ?? $santa->title,
            'budget_min' => $santa->budget_min,
            'budget_max' => $santa->budget_max,
            'exchange_date' => $next,
            'theme' => $santa->theme,
            'previous_group_id' => $santa->id,
        ]);

        $language = $santa->market->language();
        $organiserName = $santa->organiser?->displayName() ?? '';

        foreach ($santa->members()->get() as $old) {
            $isOrganiser = $old->user_id !== null && $old->user_id === $santa->owner_user_id;

            $member = SecretSantaMember::create([
                'group_id' => $new->id,
                'user_id' => $old->user_id,
                'email' => $old->email,
                'display_name' => $old->display_name,
                'joined_at' => $isOrganiser ? now() : null,
                'wishlist_id' => $old->wishlist_id,
                'exclusions' => $old->exclusions,
                'interests' => $old->interests ?? [],
                'hints' => $old->hints,
            ]);

            if (! $isOrganiser) {
                $this->note($new, $member, 'again',
                    __('site.santa.mail.again_subject', ['title' => $new->title], $language),
                    [__('site.santa.mail.again_body', ['name' => $organiserName, 'title' => $new->title], $language)],
                    __('site.santa.mail.again_cta', [], $language),
                );
            }
        }

        return $new;
    }

    /**
     * Who each member had in the group this one repeats, as extra exclusions.
     *
     * @param  Collection<int, SecretSantaMember>  $members
     * @return array<int, list<int>>
     */
    private function lastYear(SecretSantaGroup $santa, Collection $members): array
    {
        if ($santa->previous_group_id === null) {
            return [];
        }

        $before = SecretSantaMember::query()->where('group_id', $santa->previous_group_id)->get()->keyBy('id');
        $now = $members->keyBy(fn (SecretSantaMember $m) => mb_strtolower((string) $m->email));
        $map = [];

        foreach ($before as $old) {
            $giver = $now->get(mb_strtolower((string) $old->email));
            $hadBefore = $old->assigned_member_id === null ? null : $before->get((int) $old->assigned_member_id);
            $giftee = $hadBefore === null ? null : $now->get(mb_strtolower((string) $hadBefore->email));

            if ($giver !== null && $giftee !== null && $giver->id !== $giftee->id) {
                $map[$giver->id] = [$giftee->id];
            }
        }

        return $map;
    }

    /**
     * @param  array<int, list<int>>  $a
     * @param  array<int, list<int>>  $b
     * @return array<int, list<int>>
     */
    private function merged(array $a, array $b): array
    {
        foreach ($b as $id => $more) {
            $a[$id] = array_values(array_unique([...($a[$id] ?? []), ...$more]));
        }

        return $a;
    }
}
