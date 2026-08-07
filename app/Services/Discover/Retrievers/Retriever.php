<?php

declare(strict_types=1);

namespace App\Services\Discover\Retrievers;

use App\Services\Discover\Candidate;
use App\Services\Discover\DiscoveryRequest;

/**
 * One way of finding candidates.
 *
 * The strategy that makes modes-as-config work. A profile names retrievers and
 * weights; the engine looks them up and mixes them. Adding a retriever is a new
 * class and a registry entry — never a change to the pipeline.
 *
 * Every retriever must:
 *
 * - **Enforce the quality and giftability filter.** Not optional per mode. A
 *   printer cartridge or an out-of-stock listing is wrong in Search and wrong
 *   in Serendipity; letting one mode skip the filter means the filter is
 *   documentation rather than a guarantee.
 * - **Degrade rather than throw.** A source being down must cost its candidates
 *   and nothing else — the other retrievers still return, and the user sees a
 *   shorter list rather than an error.
 * - **Set named signals**, not a score. Scoring is the ranker's job, and a
 *   retriever that scores makes the profile's α/β/γ meaningless.
 */
interface Retriever
{
    /** Stable key, as used in a mode profile's retriever map. */
    public function key(): string;

    /**
     * Whether this retriever can run at all right now.
     *
     * False when its dependency is missing — the semantic retriever without an
     * embedding index, the image retriever without a vector. The engine skips
     * it and renormalises the remaining weights, so a mode that leans on an
     * unavailable retriever degrades to its other ones instead of returning
     * nothing.
     */
    public function isAvailable(DiscoveryRequest $request): bool;

    /**
     * @param  int  $take  how many candidates this retriever's weight has earned
     * @return list<Candidate>
     */
    public function retrieve(DiscoveryRequest $request, int $take): array;
}
