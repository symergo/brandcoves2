<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Interest;
use App\Enums\Market;
use Illuminate\Support\Facades\DB;

/**
 * The interests people type that the vocabulary does not have.
 *
 * The wizard's "anything else?" box accepts any word, and every brief is
 * recorded as an event (`gift.suggest`, append-only, no personal data: the
 * interests and the vibe, never the person). Words that are not one of the
 * enum interests are the demand the closed vocabulary has not met yet, and
 * this ranks them so an interest is added when people keep asking for it
 * rather than when somebody guesses they might (owner's call, 2026-09-14).
 *
 * Read from the events and not from `recipients.interests`: the events are
 * the designed signal for exactly this question, and a recipient's saved
 * taste is personal data that should not be mined for it.
 *
 * Adding an interest stays a code change on purpose — a case on `Interest`,
 * a seed in `AngleMap`, a label in four languages — because each of those
 * has to be written by a person. This only says which ones are worth it.
 */
class InterestCandidates
{
    /**
     * @return list<array{interest: string, count: int, lastSeen: string}>
     */
    public function top(Market $market, int $limit = 50, int $days = 90): array
    {
        $known = Interest::values();

        $rows = DB::select(
            <<<'SQL'
            SELECT lower(trim(word)) AS interest,
                   count(*) AS count,
                   max(events.created_at) AS last_seen
            FROM events
            CROSS JOIN LATERAL jsonb_array_elements_text(
                CASE WHEN jsonb_typeof(payload -> 'interests') = 'array' THEN payload -> 'interests' ELSE '[]'::jsonb END
            ) AS word
            WHERE events.kind = 'gift.suggest'
              AND events.market = ?
              AND events.created_at >= ?
              AND trim(word) <> ''
            GROUP BY lower(trim(word))
            ORDER BY count DESC, last_seen DESC
            SQL,
            [$market->value, now()->subDays($days)->toDateTimeString()],
        );

        $out = [];

        foreach ($rows as $row) {
            if (in_array($row->interest, $known, true)) {
                continue;
            }

            $out[] = [
                'interest' => $row->interest,
                'count' => (int) $row->count,
                'lastSeen' => (string) $row->last_seen,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
