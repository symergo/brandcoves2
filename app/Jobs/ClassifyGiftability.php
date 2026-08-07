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

        ProductGroup::query()
            ->forMarket($this->market)
            ->select(['id', 'title', 'category', 'min_price'])
            ->chunkById(1000, function ($groups) use ($classifier, &$giftable, &$rejected): void {
                $updates = [];

                foreach ($groups as $group) {
                    $verdict = $classifier->classify(
                        $group->title,
                        $group->category,
                        $group->min_price,
                    );

                    $verdict->giftable ? $giftable++ : $rejected++;

                    $updates[] = [
                        'id' => $group->id,
                        'giftable' => $verdict->giftable,
                        'giftable_reason' => $verdict->evidence === null
                            ? $verdict->reason
                            : $verdict->reason.': '.$verdict->evidence,
                    ];
                }

                $this->flush($updates);
            });

        Log::info('Giftability classified', [
            'market' => $this->market->value,
            'giftable' => $giftable,
            'rejected' => $rejected,
        ]);
    }

    /**
     * One statement per chunk.
     *
     * A per-row UPDATE over 70,000 groups is 70,000 round trips; a CASE
     * expression over a thousand ids is one. The difference is minutes.
     *
     * @param  list<array{id: int, giftable: bool, giftable_reason: string}>  $updates
     */
    private function flush(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $ids = array_column($updates, 'id');

        $giftableCase = 'CASE id';
        $reasonCase = 'CASE id';
        $bindings = [];

        foreach ($updates as $row) {
            $giftableCase .= ' WHEN ? THEN ?';
            $bindings[] = $row['id'];
            $bindings[] = $row['giftable'];
        }

        $giftableCase .= ' END';

        foreach ($updates as $row) {
            $reasonCase .= ' WHEN ? THEN ?';
            $bindings[] = $row['id'];
            $bindings[] = $row['giftable_reason'];
        }

        $reasonCase .= ' END';

        DB::update(
            "UPDATE product_groups SET giftable = {$giftableCase}, giftable_reason = {$reasonCase}, updated_at = now() ".
            'WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
            [...$bindings, ...$ids],
        );
    }
}
