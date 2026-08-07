<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Services\Discovery\CatalogueStats;
use App\Services\Discovery\SerendipityEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fills `surprise_score` and `surprise_breakdown` for one market.
 *
 * Runs after giftability, because the serendipity gate reads that verdict — a
 * product already known to be a printer cartridge should never be scored as an
 * exciting find.
 *
 * The catalogue statistics are built once for the whole run and reused for
 * every row: serendipity is a comparison against the rest of the catalogue, so
 * per-row stats would be both wrong and tens of thousands of queries.
 */
class ScoreSerendipity implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public Market $market) {}

    public function handle(): void
    {
        $stats = CatalogueStats::build($this->market);
        $engine = new SerendipityEngine($stats);

        $scored = 0;
        $gated = 0;

        ProductGroup::query()
            ->forMarket($this->market)
            ->chunkById(1000, function ($groups) use ($engine, &$scored, &$gated): void {
                $rows = [];

                foreach ($groups as $group) {
                    $result = $engine->score($group);

                    $result['score'] > 0 ? $scored++ : $gated++;

                    $rows[] = [
                        'id' => $group->id,
                        'score' => $result['score'],
                        'breakdown' => json_encode($result['breakdown']),
                    ];
                }

                $this->flush($rows);
            });

        Log::info('Serendipity scored', [
            'market' => $this->market->value,
            'scored' => $scored,
            'gated' => $gated,
            'catalogue' => $stats->total,
        ]);
    }

    /**
     * One UPDATE per chunk via a VALUES join.
     *
     * A CASE expression works for a couple of columns but gets unreadable with
     * jsonb in the mix; joining against a VALUES list is the same single round
     * trip and says what it does.
     *
     * @param  list<array{id: int, score: float, breakdown: string}>  $rows
     */
    private function flush(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($rows), '(?::bigint, ?::float, ?::jsonb)'));
        $bindings = [];

        foreach ($rows as $row) {
            $bindings[] = $row['id'];
            $bindings[] = $row['score'];
            $bindings[] = $row['breakdown'];
        }

        DB::update(
            "UPDATE product_groups g
             SET surprise_score = v.score,
                 surprise_breakdown = v.breakdown,
                 updated_at = now()
             FROM (VALUES {$placeholders}) AS v(id, score, breakdown)
             WHERE g.id = v.id",
            $bindings,
        );
    }
}
