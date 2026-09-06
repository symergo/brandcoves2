<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AlertState;
use App\Models\Notification;
use App\Models\SearchAlert;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Run every watched search and say what is new.
 *
 * Once a day, after the overnight grouping has landed: the catalogue changes
 * with ingestion, twice a day, and a check between two ingests re-reads the
 * same rows. Against the stored catalogue only — `liveTerm: ''` keeps the live
 * connectors out of it, because a scheduled job spending bol requests on every
 * watched term every morning is the cost the search throttle exists to bound.
 *
 * "New" means an id the watch has not seen. The seen set is capped so a
 * broad term watched for a year does not grow a row without limit; the oldest
 * ids fall off, and a product that left the results for months and came back
 * is, from the watcher's chair, new again.
 */
class CheckSearchAlerts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    /** How many matches one run reads per watch, and how many ids a watch remembers. */
    private const MATCHES = 200;

    private const REMEMBERED = 1000;

    public function uniqueId(): string
    {
        return 'check-search-alerts';
    }

    public function handle(SearchService $search): void
    {
        $checked = 0;
        $notified = 0;

        SearchAlert::query()
            ->where('state', AlertState::Active->value)
            ->chunkById(100, function ($alerts) use ($search, &$checked, &$notified): void {
                foreach ($alerts as $alert) {
                    $checked++;

                    $matches = $search->matchingGroupIds(new SearchQuery(
                        market: $alert->market,
                        term: $alert->term,
                        maxPrice: $alert->max_price,
                        logged: false,
                        liveTerm: '',
                    ), self::MATCHES);

                    $seen = array_map('intval', (array) $alert->seen_group_ids);
                    $new = array_values(array_diff($matches, $seen));

                    $update = ['last_checked_at' => now()];

                    if ($new !== []) {
                        $this->notify($alert, count($new));
                        $update['seen_group_ids'] = array_slice(array_values(array_unique([...$new, ...$seen])), 0, self::REMEMBERED);
                        $update['notified_at'] = now();
                        $notified++;
                    }

                    $alert->update($update);
                }
            });

        Log::info('Search alerts checked', ['checked' => $checked, 'notified' => $notified]);
    }

    private function notify(SearchAlert $alert, int $count): void
    {
        $language = $alert->market->language();

        Notification::create([
            'user_id' => $alert->user_id,
            'kind' => 'search_match',
            // Written in the market's language now, as a reminder is: this is
            // the only moment that knows which language the watch was set in.
            'title' => (string) __('site.notifications.search_match_title', ['term' => $alert->term], $language),
            'body' => null,
            'url' => $alert->searchPath(),
            'payload' => [
                'term' => $alert->term,
                'count' => $count,
                'max_price' => $alert->max_price,
            ],
        ]);
    }
}
