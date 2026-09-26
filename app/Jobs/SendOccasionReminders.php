<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Mail\OccasionReminderMail;
use App\Models\Friendship;
use App\Models\Notification;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\SecretSantaGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Gift\ReminderIdeas;
use App\Services\Settings\ReminderSettingsStore;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;

/**
 * "Your mother's birthday is in two weeks."
 *
 * Three things carry a date, and each was written, validated and scrubbed long
 * before anything read it: `recipients.birthday`,
 * `secret_santa_groups.exchange_date`, and `wishlists.event_date` — the occasion
 * an owner types into the Gelegenheid panel, which until now was rendered on the
 * shared page and did nothing else.
 *
 * ## The windows are a setting, not a constant
 *
 * They were `const LEAD_DAYS = [14, 3]`, so changing them was a deploy — for a
 * number whose right value is a judgement about how people shop. They now come
 * from `config('giftcoves.reminders.lead_days')`, default 30/15/2, editable at
 * **Operations → Reminders**; see
 * {@see ReminderSettingsStore}.
 *
 * A single lead has to be either too early to be useful or too late to be
 * actionable. Thirty days is "there is time to find something good", fifteen is
 * "decide", two is "it is now".
 *
 * ## Fire once per occurrence
 *
 * The discipline the price alerts already use. A reminder that repeats every day
 * gets muted, and once muted the *real* one is muted too — so the failure mode
 * of over-notifying is not noise, it is silence at the moment that matters.
 *
 * Dedupe is by `(user, kind, payload->key)` against the notifications already
 * written, which needs no extra table and cannot drift out of step with what was
 * actually delivered. The key carries the year and the lead, so a birthday fires
 * at most once per window per year — including when the scheduler replays a day,
 * which a redeploy makes it do.
 *
 * **The notification row is also the email's ledger.** Mail goes out only on the
 * pass that wrote the row, so switching email on does not re-send everything
 * already reminded, and a queue retry after the row exists sends nothing twice.
 *
 * ## Every string is resolved in the market's language, explicitly
 *
 * A queued job has no request and therefore no locale: it runs in whatever
 * `app.locale` says, which is English. So each `__()` here is passed the
 * language of the market the reminder is about — a reminder about a Dutch list
 * arriving in English is the bug that prevents.
 *
 * ## Ideas ready, about two weeks out
 *
 * On the window nearest two weeks (`reminders.ideas_lead_days`, 14; with the
 * shipped windows that is the fifteen-day one), a reminder about somebody
 * else, a saved person's birthday or the occasion on a list about them,
 * carries three ideas for that person: their taste, their budget, and nothing
 * they were already given ({@see ReminderIdeas}). Only in the email, only when
 * the email will go, and once per person, occasion and year: the key is
 * written into the notification's payload as `ideas_key`, so a birthday and a
 * birthday list for the same person on the same day send the ideas once.
 * No AI anywhere in it; the engine is retrieval and arithmetic.
 *
 * ## Whoever turned the emails off gets none
 *
 * `users.reminder_emails_off_at`, set from the link in every reminder email
 * or the switch on the notifications page. The inbox row is still written.
 */
class SendOccasionReminders implements ShouldQueue
{
    /**
     * When a friend's birthday is announced: a fortnight, five days, and the day.
     *
     * A constant rather than config: these are not an operator's dial, they are
     * the shape of the feature. Two weeks is enough to think of something, five
     * days is enough to order it and have it arrive, and the morning itself is
     * the one nobody wants to miss. See `handle()` for why they are not the
     * shared `lead_days`.
     */
    private const FRIEND_BIRTHDAY_LEADS = [14, 5, 0];

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $today = CarbonImmutable::now()->startOfDay();

        foreach ($this->leadDays() as $lead) {
            $target = $today->addDays($lead);

            $this->remindBirthdays($target, $lead);
            $this->remindExchanges($target, $lead);
            $this->remindListOccasions($target, $lead);
        }

        /*
         * A friend's birthday runs on its own windows, not the shared ones.
         *
         * `leadDays()` is an administrator setting covering recipients, Secret
         * Santa and list occasions — things somebody is *organising*, where one
         * early warning is the whole job. A friend's birthday is different in
         * two ways: it is the one date people genuinely forget, and it stays
         * useful right up to the morning of.
         *
         * So: two weeks to think, five days to actually order something, and
         * the day itself. `leadDays()` also filters `0` away — with good reason
         * for a shared setting somebody might type by hand — while these are
         * keyed per year and per lead, so the day-of reminder fires once and
         * not every morning.
         */
        foreach (self::FRIEND_BIRTHDAY_LEADS as $lead) {
            $this->remindFriendBirthdays($today->addDays($lead), $lead);
        }
    }

    /**
     * The windows, from config, defensively.
     *
     * The store already sorts, de-duplicates and caps what an administrator
     * types. This repeats the filter rather than trusting it, because config is
     * also reachable from a test and from anything that calls `config()->set()`
     * — and a `0` here would remind everybody about today, every day, forever.
     *
     * @return list<int>
     */
    private function leadDays(): array
    {
        return collect((array) config('giftcoves.reminders.lead_days', [30, 15, 2]))
            ->map(fn ($day): int => (int) $day)
            ->filter(fn (int $day): bool => $day > 0 && $day <= 365)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * The window the ideas ride on: the configured lead nearest two weeks.
     *
     * Not a window of its own. A fourth email a day before or after the
     * fifteen-day one would be the over-notifying this job is careful to
     * avoid, so the ideas join the reminder that is going out anyway. A tie
     * goes to the earlier window, which leaves more time to order.
     */
    private function ideasLead(): ?int
    {
        if (! config('giftcoves.reminders.ideas', true)) {
            return null;
        }

        $wanted = (int) config('giftcoves.reminders.ideas_lead_days', 14);
        $best = null;

        foreach ($this->leadDays() as $lead) {
            if ($best === null || abs($lead - $wanted) < abs($best - $wanted)) {
                $best = $lead;
            }
        }

        return $best;
    }

    /** A market key to the language its copy is written in. */
    private static function languageOf(string $market): string
    {
        return Market::tryFrom($market)?->language() ?? 'en';
    }

    private function remindBirthdays(CarbonImmutable $target, int $lead): void
    {
        Recipient::query()
            ->whereNotNull('birthday')
            ->whereNotNull('owner_user_id')
            // Day and month only: a birthday recurs, the stored year does not.
            ->whereRaw('EXTRACT(MONTH FROM birthday) = ? AND EXTRACT(DAY FROM birthday) = ?', [
                $target->month,
                $target->day,
            ])
            ->chunkById(200, function ($recipients) use ($target, $lead): void {
                foreach ($recipients as $recipient) {
                    /*
                     * `toBase()`: Eloquent's `value()` returns the cast, a
                     * Market enum, which `(string)` cannot convert. Until
                     * 2026-09-28 this threw for every person with a list, so a
                     * birthday reminder reached only people nobody had made a
                     * list for.
                     */
                    $market = (string) ($recipient->wishlists()->toBase()->value('market') ?? 'en');
                    $language = self::languageOf($market);

                    $this->notifyOnce(
                        userId: (int) $recipient->owner_user_id,
                        kind: 'occasion.birthday',
                        key: $recipient->id.':'.$target->year.':'.$lead,
                        title: __('site.reminders.birthday_title', ['name' => $recipient->name], $language),
                        body: __('site.reminders.lead', ['days' => $lead, 'name' => $recipient->name], $language),
                        // Straight to the ideas for this person, not an empty
                        // wizard (docs/features/gift-history.md).
                        url: '/'.$market.'/gift?for='.$recipient->id,
                        language: $language,
                        tokens: ['name' => $recipient->name, 'days' => $lead],
                        ideasFor: $lead === $this->ideasLead() ? [
                            'recipient' => $recipient,
                            'occasion' => 'birthday',
                            'key' => $recipient->id.':birthday:'.$target->year,
                        ] : null,
                    );
                }
            });
    }

    /**
     * A friend's birthday, from either of the two places one can be written.
     *
     * `friendships` carries the date **you** wrote down about them (day and
     * month, no year), and `users.birthday` carries the one they published
     * themselves — which wins, and only if they left `friends_see_birthday` on.
     * See docs/features/friends.md: they are two different facts and a date
     * somebody guessed must not outrank the person's own.
     *
     * One query for the whole sweep. This runs on the scheduler, so it costs a
     * request nothing — and it is the only place these two columns are read for
     * anything, which is what makes them worth having.
     */
    private function remindFriendBirthdays(CarbonImmutable $target, int $lead): void
    {
        Friendship::query()
            ->with('friend')
            ->where(fn ($q) => $q
                // The date you wrote down.
                ->where(fn ($q) => $q
                    ->where('friend_birthday_month', $target->month)
                    ->where('friend_birthday_day', $target->day))
                // Or the one they published, if they show it.
                ->orWhereHas('friend', fn ($q) => $q
                    ->where('friends_see_birthday', true)
                    ->whereNotNull('birthday')
                    ->whereRaw('EXTRACT(MONTH FROM birthday) = ? AND EXTRACT(DAY FROM birthday) = ?', [
                        $target->month,
                        $target->day,
                    ])))
            ->chunkById(200, function ($friendships) use ($target, $lead): void {
                foreach ($friendships as $friendship) {
                    $friend = $friendship->friend;

                    if ($friend === null) {
                        continue;
                    }

                    /*
                     * Their own date wins when they publish one. Otherwise the
                     * note — and if the note is what matched, it is already the
                     * right day.
                     */
                    $publishes = $friend->friends_see_birthday && $friend->birthday !== null;

                    if ($publishes && ($friend->birthday->month !== $target->month
                        || $friend->birthday->day !== $target->day)) {
                        continue;
                    }

                    // `toBase()`, for the reason given in remindBirthdays().
                    $market = (string) (Wishlist::query()
                        ->where('owner_user_id', $friendship->user_id)
                        ->toBase()
                        ->value('market') ?? 'en');
                    $language = self::languageOf($market);
                    $name = $friend->displayName();

                    $this->notifyOnce(
                        userId: (int) $friendship->user_id,
                        kind: 'occasion.friend_birthday',
                        key: $friendship->friend_id.':'.$target->year.':'.$lead,
                        title: $lead === 0
                            ? __('site.reminders.birthday_today_title', ['name' => $name], $language)
                            : __('site.reminders.birthday_title', ['name' => $name], $language),
                        /*
                         * "Nog 0 dagen" is not a sentence anybody wrote on
                         * purpose. The day itself gets its own line, which is
                         * also the only one that has nothing to suggest doing
                         * about it in advance.
                         */
                        body: $lead === 0
                            ? __('site.reminders.birthday_today', ['name' => $name], $language)
                            : __('site.reminders.lead', ['days' => $lead, 'name' => $name], $language),
                        url: '/'.$market.'/friends',
                        language: $language,
                        tokens: ['name' => $name, 'days' => $lead],
                    );
                }
            });
    }

    private function remindExchanges(CarbonImmutable $target, int $lead): void
    {
        SecretSantaGroup::query()
            ->whereDate('exchange_date', $target->toDateString())
            ->with('members')
            ->chunkById(50, function ($groups) use ($target, $lead): void {
                foreach ($groups as $group) {
                    $language = $group->market->language();

                    foreach ($group->members as $member) {
                        if ($member->user_id === null || $member->marked_done_at !== null) {
                            continue;
                        }

                        /*
                         * Sent to each member about their own shopping, never to
                         * the organiser as a list of who is lagging. Naming the
                         * people who have not bought yet would tell the organiser
                         * something about the state of everyone else's gift,
                         * which is the one thing the group page carefully avoids.
                         */
                        $this->notifyOnce(
                            userId: (int) $member->user_id,
                            kind: 'occasion.exchange',
                            key: $group->id.':'.$target->year.':'.$lead,
                            title: __('site.reminders.exchange_title', ['title' => $group->title], $language),
                            body: __('site.reminders.lead', ['days' => $lead, 'name' => $group->title], $language),
                            url: '/'.$group->market->value."/santa/{$group->id}/me/{$member->join_token}",
                            language: $language,
                            tokens: ['name' => $group->title, 'title' => $group->title, 'days' => $lead],
                        );
                    }
                }
            });
    }

    /**
     * The occasion on a list — "Dad's graduation is in 15 days".
     *
     * The one date of the three that had no reminder at all.
     *
     * ## Whose date it is decides what the sentence says
     *
     * On a list *about somebody else* the occasion is the recipient's and the
     * owner is a co-giver, so "Dad's graduation is in 15 days" is exactly the
     * nudge. On a wish list of your own it is your own event, and the reminder
     * is not "buy something" but "your list is about to matter — is it ready?".
     * Two sentences, chosen by whether the list names a recipient.
     *
     * ## The owner, and only the owner
     *
     * The people who most need reminding are whoever claimed something, and they
     * cannot be reached: a claim is stored as a one-way hash precisely so the
     * list cannot say who made it (invariant #4). Reaching them would mean
     * undoing the thing that makes the feature work. The owner is who the site
     * knows, and on the two kinds where buying is organised the owner is the
     * organiser.
     *
     * An anonymous owner has no inbox and no address, and is excluded by the
     * `whereNotNull` rather than deeper in — `notifications.user_id` is NOT
     * NULL, so there is nowhere to put the row.
     */
    private function remindListOccasions(CarbonImmutable $target, int $lead): void
    {
        Wishlist::query()
            ->whereNotNull('event_date')
            ->whereNotNull('owner_user_id')
            /*
             * The exact date, not the day-and-month a birthday matches on. A
             * wedding or a graduation happens once, and reminding somebody
             * every year about a date that has passed is worse than silence.
             */
            ->whereDate('event_date', $target->toDateString())
            ->with('recipient')
            ->chunkById(200, function ($lists) use ($target, $lead): void {
                foreach ($lists as $list) {
                    $language = $list->market->language();

                    /*
                     * The occasion's own name where there is one, the list's
                     * title otherwise. `event_date` is storable without an
                     * `event_type` — the panel keeps them separate on purpose —
                     * and "your  is in 15 days" is the sentence that produces.
                     */
                    $occasion = $list->event_type?->label($language) ?? $list->displayTitle($language);
                    $about = $list->recipient?->name;

                    $this->notifyOnce(
                        userId: (int) $list->owner_user_id,
                        kind: 'occasion.list',
                        key: $list->id.':'.$target->year.':'.$lead,
                        title: $about === null
                            ? __('site.reminders.list_title_mine', ['occasion' => $occasion], $language)
                            : __('site.reminders.list_title', [
                                'name' => $about,
                                'occasion' => $occasion,
                            ], $language),
                        body: $about === null
                            ? __('site.reminders.list_lead_mine', ['days' => $lead], $language)
                            : __('site.reminders.lead', ['days' => $lead, 'name' => $about], $language),
                        url: '/'.$list->market->value."/lists/{$list->id}",
                        language: $language,
                        tokens: [
                            'name' => $about ?? '',
                            'occasion' => $occasion,
                            'days' => $lead,
                        ],
                        /*
                         * Ideas only on a list about somebody else: on a wish
                         * list of your own the occasion is yours, and the
                         * reminder is about your list, not a present.
                         */
                        ideasFor: $list->recipient !== null && $lead === $this->ideasLead() ? [
                            'recipient' => $list->recipient,
                            'occasion' => $list->event_type?->value,
                            'key' => $list->recipient->id.':'.($list->event_type?->value ?? 'list-'.$list->id).':'.$target->year,
                        ] : null,
                    );
                }
            });
    }

    /**
     * Write the notification unless this exact occurrence already produced one,
     * and email it on the pass that wrote it.
     *
     * The row is the ledger for both channels. Sending mail outside the "did we
     * just create it" branch would re-send the whole backlog the first morning
     * after email was switched on.
     *
     * `$ideasFor` names the person the ideas are for, when this reminder
     * carries them (the window nearest two weeks, about somebody else).
     *
     * @param  array{recipient: Recipient, occasion: string|null, key: string}|null  $ideasFor
     */
    private function notifyOnce(
        int $userId,
        string $kind,
        string $key,
        string $title,
        string $body,
        string $url,
        string $language,
        array $tokens = [],
        ?array $ideasFor = null,
    ): void {
        $exists = Notification::query()
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->where(fn ($q) => $q->whereRaw("payload->>'key' = ?", [$key]))
            ->exists();

        if ($exists) {
            return;
        }

        $market = Market::tryFrom((string) explode('/', ltrim($url, '/'))[0]) ?? Market::En;
        $ideas = $ideasFor === null ? [] : $this->ideas($userId, $ideasFor, $market);

        DB::transaction(fn () => Notification::create([
            'user_id' => $userId,
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'payload' => ['key' => $key] + ($ideas === [] ? [] : [
                // The ledger for "ideas once per person, occasion and year".
                'ideas_key' => $ideasFor['key'],
                'ideas' => array_map(fn (ProductGroup $g) => $g->id, $ideas),
            ]),
        ]));

        $this->email($userId, $title, $body, $url, $language, $tokens, $market, $ideas, $ideasFor['recipient'] ?? null);
    }

    /**
     * Three ideas for this person, or none.
     *
     * None when the email will not go (there is nowhere to show them; the
     * inbox links to the Gift Finder, which has them live), or when this
     * person's ideas already went for this occasion this year.
     *
     * @param  array{recipient: Recipient, occasion: string|null, key: string}  $ideasFor
     * @return list<ProductGroup>
     */
    private function ideas(int $userId, array $ideasFor, Market $market): array
    {
        if ($this->emailableUser($userId) === null) {
            return [];
        }

        $sent = Notification::query()
            ->where('user_id', $userId)
            ->whereRaw("payload->>'ideas_key' = ?", [$ideasFor['key']])
            ->exists();

        if ($sent) {
            return [];
        }

        return app(ReminderIdeas::class)->for($ideasFor['recipient'], $market, $ideasFor['occasion'], 3);
    }

    /**
     * The account to email, or null when reminder email is off: for
     * everyone (`reminders.email`), or for this person, who turned it off.
     */
    private function emailableUser(int $userId): ?User
    {
        if (! config('giftcoves.reminders.email', true)) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null || blank($user->email) || $user->reminder_emails_off_at !== null) {
            return null;
        }

        return $user;
    }

    /**
     * The same reminder, where it will actually be read.
     *
     * After the row is committed, never instead of it: the inbox is the record
     * and email is the delivery. A send that fails leaves the notification
     * standing, which is the right way round — the reminder still exists, it
     * just did not travel.
     *
     * Queued rather than sent inline. This job already runs on the queue, and a
     * mail transport that hangs would otherwise stall the whole morning's
     * reminders behind one address.
     */
    /**
     * @param  array<string, string|int>  $tokens
     * @param  list<ProductGroup>  $ideas
     */
    private function email(
        int $userId,
        string $title,
        string $body,
        string $url,
        string $language,
        array $tokens,
        Market $market,
        array $ideas = [],
        ?Recipient $about = null,
    ): void {
        $user = $this->emailableUser($userId);

        if ($user === null) {
            return;
        }

        // Absolute: an email has no origin to resolve a path against.
        $base = rtrim((string) config('app.url'), '/');

        Mail::to($user->email)->queue(new OccasionReminderMail(
            heading: $title,
            body: $body,
            url: $base.$url,
            language: $language,
            tokens: $tokens,
            ideas: $about === null ? [] : array_map(fn (ProductGroup $group) => [
                'title' => $group->displayTitle(),
                'image' => $group->image_url,
                'price' => $group->min_price === null
                    ? null
                    : (string) Number::currency($group->min_price / 100, $market->currency(), $market->hrefLang()),
                'url' => $base.$group->path(),
                // To the person's page with this idea on top and the save
                // button beside it: one click from there, never a GET that
                // adds (mail scanners open every link in a message).
                'addUrl' => $base.'/'.$market->value."/people/{$about->id}?add={$group->id}",
            ], $ideas),
            ideasUrl: $about === null || $ideas === [] ? null : $base.'/'.$market->value.'/gift?for='.$about->id,
            name: $about?->name,
            // Permanent, like the Cove digest's: an unsubscribe link that
            // expires fails exactly when somebody is annoyed enough to use it.
            unsubscribeUrl: URL::signedRoute('reminders.stop', ['market' => $market->value, 'user' => $user->id]),
        ));
    }
}
