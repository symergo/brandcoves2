<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MatchStatus;
use App\Models\MatchCandidate;
use App\Models\User;
use App\Services\Identity\GroupMerger;
use App\Services\Identity\MatchKeeper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * "The same" for a whole rule: merge every waiting pair a rule proposed.
 *
 * Pressed by a person on the match review screen, per rule (owner's request,
 * 2026-09-26), after a confirmation that names the count and the rule's
 * precision. Nothing merges without that press; see docs/features/match-review.md.
 *
 * In batches of BATCH pairs per run, dispatching the next run until the rule's
 * queue is empty (invariant 8: long work is chunked and resumable, and a rule
 * can hold a thousand pairs). Each pair is re-read before it is merged, because
 * an earlier merge in the same batch may already have settled it: GroupMerger
 * moves or closes the pending pairs that involved the product it merged away.
 * A pair the merger refuses (a split recorded in between, two barcode
 * products) is left waiting for a person and carried in `$skipped` so the
 * next run does not try it again.
 */
#[Queue('editorial')]
class MergeRuleCandidates implements ShouldQueue
{
    use Queueable;

    public const BATCH = 200;

    public int $timeout = 600;

    /**
     * @param  list<int>  $skipped  candidate ids already refused in an earlier run
     */
    public function __construct(
        public readonly string $rule,
        public readonly ?string $market,
        public readonly ?int $userId,
        public readonly array $skipped = [],
    ) {}

    public function handle(GroupMerger $merger): void
    {
        $by = $this->userId === null ? null : User::query()->find($this->userId);
        $skipped = $this->skipped;
        $merged = 0;

        for ($done = 0; $done < self::BATCH; $done++) {
            $candidate = $this->pending($skipped)->with(['groupA', 'groupB'])->first();

            if ($candidate === null) {
                Log::info('match review: rule merged', ['rule' => $this->rule, 'market' => $this->market, 'merged' => $merged, 'left_for_a_person' => count($skipped)]);

                return;
            }

            $a = $candidate->groupA;
            $b = $candidate->groupB;

            if ($a === null || $b === null || $a->merged_into_id !== null || $b->merged_into_id !== null) {
                $skipped[] = $candidate->id;

                continue;
            }

            $winner = MatchKeeper::pick($a, $b);
            $loser = $winner->is($a) ? $b : $a;

            try {
                $merger->merge($loser, $winner, $by, 'match review: all of rule '.$this->rule);
                $merged++;
            } catch (InvalidArgumentException) {
                $skipped[] = $candidate->id;
            }
        }

        if ($this->pending($skipped)->exists()) {
            self::dispatch($this->rule, $this->market, $this->userId, $skipped);
        }
    }

    /**
     * @param  list<int>  $skipped
     * @return Builder<MatchCandidate>
     */
    private function pending(array $skipped)
    {
        return MatchCandidate::query()
            ->where('status', MatchStatus::Pending->value)
            ->where('rule', $this->rule)
            ->when($this->market, fn ($q, string $m) => $q->where('market', $m))
            ->when($skipped !== [], fn ($q) => $q->whereNotIn('id', $skipped))
            ->orderBy('id');
    }
}
