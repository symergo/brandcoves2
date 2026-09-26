<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * "After the moka pot: a grinder."
 *
 * A scored follow-on to something already given, with the one reason that
 * carried it and the past gift it follows. The page says both, because an idea
 * that names what it follows is one a giver can judge at a glance.
 */
final readonly class NextStep
{
    /** People keep the two on the same lists (`product_links`). */
    public const OFTEN_TOGETHER = 'often_together';

    /** It is used with, or used up by, what was given (resources/content/gift-complements.php). */
    public const GOES_WITH = 'goes_with';

    /** The same brand, something else from its range. */
    public const SAME_BRAND = 'same_brand';

    public function __construct(
        public int $groupId,
        public float $score,
        public string $reason,
        /** The title of the past gift this follows. */
        public string $after,
    ) {}
}
