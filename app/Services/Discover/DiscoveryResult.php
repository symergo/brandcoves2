<?php

declare(strict_types=1);

namespace App\Services\Discover;

use App\Support\CurrentMarket;

/**
 * What one run of the pipeline produced.
 *
 * Carries the layout hint and the resolved profile alongside the items, because
 * the frontend renders by layout rather than by mode — one DiscoverySurface
 * component, nine appearances. Sending the profile back also makes the dial
 * legible: the numbers on screen are the numbers that produced the page.
 */
final readonly class DiscoveryResult
{
    /** @param list<Candidate> $items */
    public function __construct(
        public array $items,
        public ModeProfile $profile,
        public int $candidateCount = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(CurrentMarket $current): array
    {
        return [
            'items' => array_map(fn (Candidate $candidate) => [
                'id' => $candidate->group->id,
                'title' => $candidate->group->title,
                'brand' => $candidate->group->brand,
                'image' => $candidate->group->image_url,
                'category' => $candidate->group->category,
                // Cents, exactly as stored. The client formats for the market,
                // so a float never enters the pipeline.
                'price' => $candidate->group->min_price,
                'merchantCount' => $candidate->group->merchant_count,
                'inStock' => $candidate->group->in_stock,
                'discountPercent' => $candidate->group->discountPercent(),
                'url' => $current->url("p/{$candidate->group->id}/{$candidate->group->slug}"),
                /*
                 * "Why you're seeing this", from the dominant scoring factor.
                 *
                 * Required of every mode. A result surface that reorganises
                 * itself as a dial moves is incomprehensible without it — the
                 * user needs to see that the same product is here for a
                 * different reason than it was a moment ago.
                 */
                'reason' => $candidate->reason,
                'sources' => $candidate->sources,
            ], $this->items),

            'layout' => $this->profile->layout,

            'modeMeta' => [
                ...$this->profile->toArray(),
                'candidatesConsidered' => $this->candidateCount,
            ],
        ];
    }
}
