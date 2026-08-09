<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Notification;
use App\Models\Recipient;
use App\Models\SecretSantaGroup;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * "Your mother's birthday is in two weeks."
 *
 * `recipients.birthday` has been written, validated and scrubbed since the table
 * was created and never once read. Neither v1 nor v2 built this, and it is the
 * single most obvious thing to do with the data.
 *
 * ## Two weeks and three days
 *
 * Long enough to shop, not just to panic. One reminder is a nudge; the second is
 * the one people act on, and a single lead time has to be either too early to be
 * useful or too late to be actionable.
 *
 * ## Fire once per occurrence
 *
 * The discipline the price alerts already use. A reminder that repeats every day
 * gets muted, and once muted the *real* one is muted too — so the failure mode of
 * over-notifying is not noise, it is silence at the moment that matters.
 *
 * Dedupe is by `(user, kind, payload)` against the notifications already sent
 * this year, which needs no extra table and cannot drift out of step with what
 * was actually delivered.
 */
class SendOccasionReminders implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Days before the date. */
    public const LEAD_DAYS = [14, 3];

    public function handle(): void
    {
        $today = CarbonImmutable::now()->startOfDay();

        foreach (self::LEAD_DAYS as $lead) {
            $target = $today->addDays($lead);

            $this->remindBirthdays($target, $lead);
            $this->remindExchanges($target, $lead);
        }
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
                    $this->notifyOnce(
                        userId: (int) $recipient->owner_user_id,
                        kind: 'occasion.birthday',
                        key: $recipient->id.':'.$target->year.':'.$lead,
                        title: __('site.reminders.birthday_title', ['name' => $recipient->name]),
                        body: __('site.reminders.lead_'.$lead, ['name' => $recipient->name]),
                        url: '/'.($recipient->wishlists()->value('market') ?? 'en').'/gift',
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
                            title: __('site.reminders.exchange_title', ['title' => $group->title]),
                            body: __('site.reminders.lead_'.$lead, ['name' => $group->title]),
                            url: '/'.$group->market->value."/santa/{$group->id}/me/{$member->join_token}",
                        );
                    }
                }
            });
    }

    /**
     * Write the notification unless this exact occurrence already produced one.
     *
     * The uniqueness key includes the year and the lead, so the same birthday
     * fires twice a year at most and never twice for the same window — including
     * when the scheduler runs more than once in a day, which it does on a
     * redeploy.
     */
    private function notifyOnce(int $userId, string $kind, string $key, string $title, string $body, string $url): void
    {
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
    }
}
