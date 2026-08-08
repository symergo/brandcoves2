<?php

declare(strict_types=1);

namespace App\Services\Discover;

use App\Services\Discover\Retrievers\Retriever;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One pipeline, reconfigured by a Mode Profile.
 *
 * ```
 * input → query representation → retrieve → rank → present
 * ```
 *
 * Nine modes, one pipeline. A mode is a config object — a retriever mix, five
 * scoring numbers and a layout name — so adding one is a row in
 * `mode_profiles`, never a change here. If a future mode needs this class
 * edited, either the profile schema is missing a field or the thing is not
 * really a mode.
 *
 * The engine's own job is small and specific: resolve the profile (including
 * the dial's interpolation), work out how many candidates each retriever's
 * weight has earned, run them, merge, hand the pile to the ranker, and attach a
 * layout hint. Everything opinionated lives in a retriever or in the ranker.
 */
class ModeEngine
{
    /**
     * Over-fetch factor.
     *
     * The ranker needs materially more candidates than it will return, or MMR
     * has nothing to choose between and diversity collapses to whatever
     * retrieval happened to return in order.
     */
    private const OVERFETCH = 4;

    /** @var array<string, Retriever> */
    private array $retrievers = [];

    /** @param iterable<Retriever> $retrievers */
    public function __construct(
        private readonly ModeRegistry $modes,
        private readonly Ranker $ranker,
        iterable $retrievers = [],
    ) {
        foreach ($retrievers as $retriever) {
            $this->retrievers[$retriever->key()] = $retriever;
        }
    }

    /**
     * Run the pipeline.
     *
     * @param  float|null  $dial  0..1 along the intent axis; null uses the mode as declared
     * @param  float|null  $surprise  the user's own surprise dial, 0..1, 0.5 = leave alone
     */
    public function discover(
        string $mode,
        DiscoveryRequest $request,
        ?float $dial = null,
        ?float $surprise = null,
        ?int $seed = null,
    ): DiscoveryResult {
        $profile = $dial === null
            ? $this->modes->get($mode)
            : $this->modes->atPosition($dial);

        if ($surprise !== null) {
            $profile = $profile->withSurprise($surprise);
        }

        $candidates = $this->retrieve($profile, $request);
        $ranked = $this->ranker->rank($candidates, $profile, $request->limit, $seed);

        return new DiscoveryResult(
            items: $this->order($ranked, $profile),
            profile: $profile,
            candidateCount: count($candidates),
        );
    }

    /**
     * Reading order, which is not always ranking order.
     *
     * The ranker decides *which* results appear; a layout can have its own
     * opinion about the order they are read in. Compare is the case that forces
     * the distinction: it is a price ladder, and its entire content is the
     * ordering, so presenting it by score scrambles the one thing the mode is
     * for. Everywhere else score order is the answer, which is why this is a
     * profile field rather than a branch.
     *
     * @param  list<Candidate>  $items
     * @return list<Candidate>
     */
    private function order(array $items, ModeProfile $profile): array
    {
        if ($profile->order === 'price_asc') {
            usort($items, fn (Candidate $a, Candidate $b) => ($a->group->min_price ?? PHP_INT_MAX)
                <=> ($b->group->min_price ?? PHP_INT_MAX));
        }

        return $items;
    }

    /**
     * Run every retriever the profile names, in proportion to its weight.
     *
     * Weights are renormalised over the retrievers that are actually
     * *available*, so a mode leaning on the semantic retriever before pgvector
     * exists degrades onto its remaining retrievers instead of returning a
     * quarter of a page. A mode whose retrievers are all unavailable returns
     * nothing, which is correct and visible rather than silently wrong.
     *
     * @return list<Candidate>
     */
    private function retrieve(ModeProfile $profile, DiscoveryRequest $request): array
    {
        $usable = [];

        foreach ($profile->retrievers as $key => $weight) {
            $retriever = $this->retrievers[$key] ?? null;

            if ($retriever === null) {
                // Declared in a profile but not registered. Logged rather than
                // thrown: a typo in an editable config row must not take the
                // whole surface down.
                Log::warning('Unknown retriever in mode profile', ['mode' => $profile->key, 'retriever' => $key]);

                continue;
            }

            if ($weight > 0 && $retriever->isAvailable($request)) {
                $usable[$key] = ['retriever' => $retriever, 'weight' => $weight];
            }
        }

        $total = array_sum(array_column($usable, 'weight'));

        if ($total <= 0) {
            return [];
        }

        $budget = $request->limit * self::OVERFETCH;

        /** @var array<int, Candidate> $merged keyed by group id */
        $merged = [];

        foreach ($usable as $key => $entry) {
            $take = max(4, (int) ceil($budget * ($entry['weight'] / $total)));

            try {
                $found = $entry['retriever']->retrieve($request, $take);
            } catch (Throwable $e) {
                // One retriever failing costs its candidates and nothing else.
                // The user sees a shorter list, never an error page.
                report($e);

                continue;
            }

            foreach ($found as $candidate) {
                $id = $candidate->group->id;

                $merged[$id] = isset($merged[$id])
                    // Found by two retrievers. Signals merge by max, so the
                    // stronger evidence wins rather than the last one written.
                    ? $merged[$id]->withSignals($candidate->signals, $key)
                    : $candidate;
            }
        }

        return array_values($merged);
    }
}
