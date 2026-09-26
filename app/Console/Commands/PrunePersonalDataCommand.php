<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Images\ImageStore;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
        // An identity seen on one day only, which owns nothing (2026-09-26).
        'anonymous_identities_single_visit' => 30,
        'feedback' => 365,
        // This or that together: a run, then a link with no runs left (2026-09-26).
        'taste_runs' => 180,
        // A gift profile card nobody has opened for this long (2026-09-26).
        'gift_profile_cards' => 365,
    ];

    /**
     * Gift history, in years: a line about what somebody gave one of their
     * saved people goes when its year is this many years back. Counted in
     * years because that is all the line records ("given in 2021"). Ten is
     * long enough for "what did I give her for her 60th", and past it a line
     * no longer tells anybody what to avoid giving next. Quoted in
     * `resources/legal/*` as well.
     */
    public const GIFT_HISTORY_YEARS = 10;

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
         * An identity seen on one day only, a month on, that owns nothing.
         *
         * 99% of production's 2.56 million identities (2026-09-26) were this:
         * a visit without a cookie, mostly crawlers, that made a row nothing
         * would ever read. Crawlers no longer get one (TrackAnonymousIdentity);
         * this clears what they left and what one-off visitors leave.
         *
         * "One day only" is `last_seen_at` less than a day after `created_at`,
         * because the middleware refreshes `last_seen_at` at most once a day.
         * "Owns nothing" checks every table that points at an identity, not
         * just lists and recipients: a vote or a pledge is somebody's too, and
         * its foreign key would refuse the delete.
         */
        $report['one-visit identities'] = $this->prune(
            'anonymous_identities',
            fn () => $this->ownsNothing(DB::table('anonymous_identities')
                ->where('created_at', '<', now()->subDays(self::RETENTION['anonymous_identities_single_visit']))
                ->whereRaw("last_seen_at < created_at + interval '1 day'")),
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

        /*
         * This or that together (docs/features/taste-together.md). A run is
         * somebody's guesses about a real person, kept for the occasion it was
         * played for; six months covers a birthday planned well ahead and is
         * past any one occasion. A link goes once it is that old and has no
         * runs left, so a link still being played is never cut off mid-use.
         * What the giver added to the person stays on the person, with the
         * rest of what they keep there ("until you delete them").
         */
        $report['this or that runs'] = $this->prune(
            'taste_runs',
            fn () => DB::table('taste_runs')->where('updated_at', '<', now()->subDays(self::RETENTION['taste_runs'])),
            $dry,
        );

        $report['this or that links'] = $this->prune(
            'taste_invites',
            fn () => DB::table('taste_invites')
                ->where('created_at', '<', now()->subDays(self::RETENTION['taste_runs']))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('taste_runs')->whereColumn('taste_runs.taste_invite_id', 'taste_invites.id')),
            $dry,
        );

        /*
         * Gift profile cards (docs/features/gift-profile-card.md). A card is
         * meant to be opened: its maker sent the link to people who buy for
         * them. One nobody has opened for a year has done its job or never
         * will, and a taste from a year ago is out of date anyway. Opening a
         * card keeps it (at most one write a day).
         */
        $report['gift profile cards'] = $this->prune(
            'gift_profile_cards',
            fn () => DB::table('gift_profile_cards')->where('last_opened_at', '<', now()->subDays(self::RETENTION['gift_profile_cards'])),
            $dry,
        );

        /*
         * What somebody noted they gave a person (gift-history.md). Deleted
         * with the person or the account anyway; this is the ceiling for a
         * person kept for years.
         */
        $report['gift history'] = $this->prune(
            'recipient_gifts',
            fn () => DB::table('recipient_gifts')->where('given_year', '<', (int) now()->year - self::GIFT_HISTORY_YEARS),
            $dry,
        );

        $report['item pictures'] = $this->prunePictures($dry);

        foreach ($report as $label => $count) {
            $this->components->twoColumnDetail($label, ($dry ? 'would delete ' : 'deleted ').$count);
        }

        return self::SUCCESS;
    }

    /**
     * Pictures no list item refers to any more.
     *
     * A photo somebody uploaded is their personal data, and it goes when the
     * item goes. An item deleted on its own takes its picture with it
     * (WishlistItem::booted), but a list or an account deleted as a whole
     * removes its items by cascade, in the database, where no model event
     * fires. This sweep is what catches those.
     *
     * A day's grace, so a picture stored a moment before its item is saved is
     * never mistaken for an orphan.
     */
    private function prunePictures(bool $dry): int
    {
        $disk = Storage::disk(ImageStore::DISK);
        $cutoff = now()->subDay()->getTimestamp();
        $orphans = [];

        foreach ($disk->files('items') as $file) {
            if ($disk->lastModified($file) > $cutoff) {
                continue;
            }

            $used = DB::table('wishlist_items')->where('snapshot_image_url', '/media/'.$file)->exists();

            if (! $used) {
                $orphans[] = $file;
            }
        }

        if (! $dry && $orphans !== []) {
            $disk->delete($orphans);
        }

        return count($orphans);
    }

    /**
     * Only identities no row points at: every foreign key to
     * `anonymous_identities`, as listed on 2026-09-26.
     */
    private function ownsNothing(Builder $query): Builder
    {
        foreach ([
            'wishlists' => 'owner_anon_id',
            'recipients' => 'owner_anon_id',
            'challenge_attempts' => 'anon_id',
            'list_quiz_attempts' => 'anon_id',
            'gift_pledges' => 'anon_id',
            'list_messages' => 'anon_id',
            'list_item_votes' => 'anon_id',
            'list_opens' => 'anon_id',
        ] as $table => $column) {
            $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($table)->whereColumn("{$table}.{$column}", 'anonymous_identities.id'));
        }

        return $query;
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
