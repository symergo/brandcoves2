<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Mail\CoveDigestMail;
use App\Models\CoveSubscriber;
use App\Models\DailyPickSet;
use App\Services\Cove\DigestBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send today's teaser to everyone who confirmed.
 *
 * ## It refuses to send more often than it refuses to work
 *
 * Three separate guards, each closing a way this could embarrass us:
 *
 *  - **No published edition, no email.** A digest linking to a page that does not
 *    exist is worse than silence.
 *  - **No sendable content, no email.** `DigestBuilder` returns null when the
 *    edition's finds are all Amazon-sourced and there is no puzzle. A mail that
 *    only says "a page exists" teaches people the digest is not worth opening,
 *    which is the one irreversible thing a daily email can do.
 *  - **`last_sent_on`, per subscriber.** A retried job that already mailed half
 *    the list must not mail that half again.
 *
 * ## Chunked
 *
 * The list will not fit in memory forever and a redeploy mid-send must not lose
 * the work — the same reason ingestion is chunked. `last_sent_on` is written
 * per subscriber immediately after the send, so a crash costs at most one mail.
 */
class SendCoveDigest implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    /**
     * One retry.
     *
     * More would risk re-sending to anyone whose `last_sent_on` write failed
     * after the mail went out, and a duplicate daily email is the complaint that
     * makes people unsubscribe.
     */
    public int $tries = 2;

    public function __construct(
        public Market $market,
        public ?string $date = null,
    ) {}

    public function handle(DigestBuilder $builder): void
    {
        $date = $this->date === null
            ? CarbonImmutable::today()->toDateString()
            : CarbonImmutable::parse($this->date)->toDateString();

        $edition = DailyPickSet::query()
            ->forMarket($this->market)
            // Matched on the date, so a persona could not be selected anyway —
            // said explicitly because the next person to relax that date clause
            // would be emailing one out as the morning's Cove.
            ->daily()
            ->published()
            ->where('drop_date', $date)
            ->with(['picks.group'])
            ->first();

        if ($edition === null) {
            Log::info('Cove digest skipped: no published edition', [
                'market' => $this->market->value,
                'date' => $date,
            ]);

            return;
        }

        $digest = $builder->forEdition($edition, $this->market);

        if ($digest === null) {
            Log::warning('Cove digest skipped: nothing sendable', [
                'market' => $this->market->value,
                'date' => $date,
                // Almost always means every find was Amazon-sourced. Worth a
                // warning rather than an info: it is a content problem.
                'picks' => $edition->picks->count(),
            ]);

            return;
        }

        $sent = 0;

        CoveSubscriber::query()
            ->forMarket($this->market)
            ->dueFor($date)
            ->orderBy('id')
            ->chunkById(200, function ($subscribers) use ($digest, $date, &$sent): void {
                foreach ($subscribers as $subscriber) {
                    Mail::to($subscriber->getAttribute('email'))->send(new CoveDigestMail(
                        digest: $digest,
                        market: $this->market,
                        unsubscribeToken: (string) $subscriber->getAttribute('unsubscribe_token'),
                    ));

                    // Written per subscriber, not per chunk: a crash then costs
                    // one duplicate rather than two hundred.
                    $subscriber->forceFill([
                        'last_sent_on' => $date,
                        'sent_count' => $subscriber->getAttribute('sent_count') + 1,
                    ])->save();

                    $sent++;
                }
            });

        Log::info('Cove digest sent', [
            'market' => $this->market->value,
            'date' => $date,
            'recipients' => $sent,
            'finds' => count($digest['finds']),
            'omitted' => $digest['omitted'],
        ]);
    }
}
