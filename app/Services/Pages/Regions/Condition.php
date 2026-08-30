<?php

declare(strict_types=1);

namespace App\Services\Pages\Regions;

/**
 * A fact about the page that a block can require without naming it.
 *
 * The automatic rule — a block is hidden when a placeholder it uses has no value
 * — covers most cases, because a sentence about a fact almost always names it.
 * Conditions cover the rest: "read the offer count before the price" is good
 * advice only on a page where something *is* sold by several shops, and it says
 * so without printing a number.
 *
 * These are the guards that used to be hardcoded. `$facts['comparable'] > 0 ?
 * $this->line('compare_2') : null` in `PageNarrative` is now the `multi_shop`
 * key in a block's `conditions` array — the same rule, moved from a line of code
 * to a checkbox.
 */
final readonly class Condition
{
    public function __construct(
        public string $key,
        public string $label,
    ) {}
}
