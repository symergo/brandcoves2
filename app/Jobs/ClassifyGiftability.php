<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Services\Gift\GiftabilityClassifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Marks every group in a market giftable or not, with the reason.
 *
 * Runs after grouping, because the classifier reads the group's denormalised
 * title, category and cheapest price — all of which grouping is what produces.
 *
 * Rewrites every row rather than only the new ones: the classifier's rules
 * change more often than the catalogue does, and a partial pass would leave the
 * old verdict on 60,000 rows with no way to tell which. A full pass over the
 * catalogue is a few seconds of CPU and no network at all.
 */
class ClassifyGiftability implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public Market $market) {}

    public function handle(GiftabilityClassifier $classifier): void
    {
        $giftable = 0;
        $rejected = 0;
        $showable = 0;

        ProductGroup::query()
            ->forMarket($this->market)
            ->select(['id', 'title', 'category', 'min_price'])
            ->chunkById(1000, function ($groups) use ($classifier, &$giftable, &$rejected, &$showable): void {
                $updates = [];

                foreach ($groups as $group) {
                    $verdict = $classifier->classify(
                        $group->title,
                        $group->category,
                        $group->min_price,
                    );

                    $verdict->giftable ? $giftable++ : $rejected++;
                    $verdict->worthShowing && $showable++;

                    $updates[] = [
                        'id' => $group->id,
                        'giftable' => $verdict->giftable,
                        'worth_showing' => $verdict->worthShowing,
                        'giftable_reason' => $verdict->evidence === null
                            ? $verdict->reason
                            : $verdict->reason.': '.$verdict->evidence,
                    ];
                }

                $this->flush($updates);
            });

        // `showable` exceeds `giftable` by exactly the rows over the price
        // ceiling. If the two are ever equal, the split has stopped working.
        Log::info('Giftability classified', [
            'market' => $this->market->value,
            'giftable' => $giftable,
            'rejected' => $rejected,
            'worth_showing' => $showable,
        ]);
    }

    /**
     * One statement per chunk.
     *
     * A per-row UPDATE over 70,000 groups is 70,000 round trips; one statement
     * over a thousand ids is one. The difference is minutes.
     *
     * Joined against a VALUES list with explicit casts rather than built from a
     * CASE expression. PDO sends every bound parameter as text, and Postgres
     * will not coerce text into a boolean column inside a CASE — it fails with
     * "column giftable is of type boolean but expression is of type text".
     * Naming the type once in the VALUES list settles it for every row.
     *
     * @param  list<array{id: int, giftable: bool, worth_showing: bool, giftable_reason: string}>  $updates
     */
    private function flush(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($updates), '(?::bigint, ?::boolean, ?::boolean, ?::text)'));
        $bindings = [];

        foreach ($updates as $row) {
            $bindings[] = $row['id'];
            // 'true'/'false', not PHP booleans: PDO renders false as an empty
            // string, which Postgres rejects as a boolean literal.
            $bindings[] = $row['giftable'] ? 'true' : 'false';
            $bindings[] = $row['worth_showing'] ? 'true' : 'false';
            $bindings[] = $row['giftable_reason'];
        }

        DB::update(
            "UPDATE product_groups g
             SET giftable = v.giftable,
                 worth_showing = v.worth_showing,
                 giftable_reason = v.reason,
                 updated_at = now()
             FROM (VALUES {$placeholders}) AS v(id, giftable, worth_showing, reason)
             WHERE g.id = v.id",
            $bindings,
        );
    }
}
