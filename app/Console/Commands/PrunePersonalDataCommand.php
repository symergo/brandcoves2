<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Enforce the retention windows the privacy policy states.
 *
 * ## Why this exists
 *
 * GDPR Article 5(1)(e): personal data may be kept no longer than is necessary.
 * A retention period published in a privacy notice and not enforced anywhere in
 * the code is not a retention period, it is a sentence. This is the code that
 * makes those sentences true.
 *
 * ## What it covers, and why each one
 *
 * **`events`** is a behavioural log keyed on the visitor cookie or the user id:
 * click-outs, scans, gift suggestions, reactions. It had no retention at all,
 * which meant an identifier-linked record of everything a visitor had ever done
 * accumulating without limit. Ninety days is long enough to debug a week-old
 * report and to see a month-over-month trend, and short enough that the log is
 * not a history of a person.
 *
 * **Unconfirmed subscribers** are addresses somebody typed into a form and never
 * confirmed. After thirty days they are not a pending signup, they are an email
 * address we hold for no reason and with no consent.
 *
 * **Expired login tokens and anonymous identities** are the same argument: a
 * credential nobody can use and a cookie identity nobody has presented in a year
 * are both data with no remaining purpose.
 *
 * **Feedback** is free text somebody typed about the site, optionally with a
 * reply address. Both go on the same clock, and the message is deleted with the
 * address rather than kept as anonymised prose: a free-text field is whatever
 * the person put in it, which is sometimes their own name. A year is long
 * enough to act on a report and to notice the same one arriving again.
 *
 * Scheduled nightly. Idempotent, and safe to run by hand.
 */
class PrunePersonalDataCommand extends Command
{
    protected $signature = 'bc:prune-personal-data {--dry-run : Report what would be deleted and delete nothing.}';

    protected $description = 'Enforce the retention windows published in the privacy policy.';

    /**
     * Retention in days, per table.
     *
     * These numbers are quoted in `resources/legal/*` and changing one here
     * without changing it there makes the published policy false. The privacy
     * test asserts the two agree.
     */
    public const RETENTION = [
        'events' => 90,
        'search_log' => 365,
        'unconfirmed_subscribers' => 30,
        'anonymous_identities' => 365,
        'feedback' => 365,
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $report = [];

        $report['events'] = $this->prune(
            'events',
            fn () => DB::table('events')->where('created_at', '<', now()->subDays(self::RETENTION['events'])),
            $dry,
        );

        /*
         * Search terms are already aggregated by the hour and carry no link to a
         * person, so this is housekeeping rather than a privacy obligation. It is
         * here because the policy names a window for it and an unenforced window
         * is worse than none.
         */
        $report['search_log'] = $this->prune(
            'search_log',
            fn () => DB::table('search_log')->where('hour_bucket', '<', now()->subDays(self::RETENTION['search_log'])),
            $dry,
        );

        $report['unconfirmed subscribers'] = $this->prune(
            'cove_subscribers',
            fn () => DB::table('cove_subscribers')
                ->whereNull('confirmed_at')
                ->where('created_at', '<', now()->subDays(self::RETENTION['unconfirmed_subscribers'])),
            $dry,
        );

        // Expired and consumed sign-in links. A token nobody can use is a
        // credential with no purpose.
        $report['login tokens'] = $this->prune(
            'login_tokens',
            fn () => DB::table('login_tokens')->where('expires_at', '<', now()->subDay()),
            $dry,
        );

        /*
         * An anonymous identity nobody has presented in a year, and which owns
         * nothing. The ownership check matters: the cookie is what a wishlist
         * built before signup belongs to, and deleting the identity would orphan
         * the list rather than tidying anything.
         */
        $report['anonymous identities'] = $this->prune(
            'anonymous_identities',
            fn () => DB::table('anonymous_identities')
                ->where('last_seen_at', '<', now()->subDays(self::RETENTION['anonymous_identities']))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('wishlists')->whereColumn('wishlists.owner_anon_id', 'anonymous_identities.id'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('recipients')->whereColumn('recipients.owner_anon_id', 'anonymous_identities.id')),
            $dry,
        );

        /*
         * Handled or not. A report nobody got to inside a year is not going to
         * be acted on, and keeping it does not make that more likely — it only
         * keeps the address.
         */
        $report['feedback'] = $this->prune(
            'feedback',
            fn () => DB::table('feedback')->where('created_at', '<', now()->subDays(self::RETENTION['feedback'])),
            $dry,
        );

        foreach ($report as $label => $count) {
            $this->components->twoColumnDetail($label, ($dry ? 'would delete ' : 'deleted ').$count);
        }

        return self::SUCCESS;
    }

    /**
     * Delete in batches.
     *
     * A single statement over a firehose table holds a lock long enough for a
     * request to notice. Batched, nothing waits.
     *
     * @param  \Closure(): Builder  $query
     */
    private function prune(string $table, \Closure $query, bool $dry): int
    {
        if ($dry) {
            return $query()->count();
        }

        $total = 0;

        do {
            $ids = $query()->limit(5_000)->pluck('id');

            $deleted = $ids->isEmpty()
                ? 0
                : DB::table($table)->whereIn('id', $ids)->delete();

            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
