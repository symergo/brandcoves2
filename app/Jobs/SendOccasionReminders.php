<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Mail\OccasionReminderMail;
use App\Models\Notification;
use App\Models\Recipient;
use App\Models\SecretSantaGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Settings\ReminderSettingsStore;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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
 */
class SendOccasionReminders implements ShouldQueue
{
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
                    $market = (string) ($recipient->wishlists()->value('market') ?? 'en');
                    $language = self::languageOf($market);

                    $this->notifyOnce(
                        userId: (int) $recipient->owner_user_id,
                        kind: 'occasion.birthday',
                        key: $recipient->id.':'.$target->year.':'.$lead,
                        title: __('site.reminders.birthday_title', ['name' => $recipient->name], $language),
                        body: __('site.reminders.lead', ['days' => $lead, 'name' => $recipient->name], $language),
                        url: '/'.$market.'/gift',
                        language: $language,
                        tokens: ['name' => $recipient->name, 'days' => $lead],
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
    ): void {
        $exists = Notification::query()
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->where(fn ($q) => $q->whereRaw("payload->>'key' = ?", [$key]))
            ->exists();

        if ($exists) {
            return;
        }

        DB::transaction(fn () => Notification::create([
            'user_id' => $userId,
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'payload' => ['key' => $key],
        ]));

        $this->email($userId, $title, $body, $url, $language, $tokens);
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
    /** @param array<string, string|int> $tokens */
    private function email(int $userId, string $title, string $body, string $url, string $language, array $tokens = []): void
    {
        if (! config('giftcoves.reminders.email', true)) {
            return;
        }

        $user = User::query()->find($userId);

        if ($user === null || blank($user->email)) {
            return;
        }

        Mail::to($user->email)->queue(new OccasionReminderMail(
            heading: $title,
            body: $body,
            // Absolute: an email has no origin to resolve a path against.
            url: rtrim((string) config('app.url'), '/').$url,
            language: $language,
            tokens: $tokens,
        ));
    }
}
