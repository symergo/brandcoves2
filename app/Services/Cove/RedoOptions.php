<?php

declare(strict_types=1);

namespace App\Services\Cove;

/**
 * What a redo throws away.
 *
 * Redo is not the rebuild that already exists. A rebuild is idempotent — it
 * reproduces the same page from the same inputs, which is what makes a scheduler
 * retry and a redeploy safe. A redo deliberately discards the inputs: it is for
 * the Cove that came out wrong, and the whole point is to get a different one at
 * the same address.
 *
 * Two choices, because a curator has two genuinely different complaints. "These
 * are the wrong products" and "these are the right products, badly written" are
 * not the same edit, and answering both with one button would make the safe one
 * destroy a shortlist somebody spent an afternoon on.
 */
readonly class RedoOptions
{
    private function __construct(
        /** Clear the curated shortlist and let the engine choose afresh. */
        public bool $reselect,
    ) {}

    /**
     * Wrong products, wrong words.
     *
     * The shortlist is cleared and whatever is currently on the page is excluded
     * from the next selection — without that exclusion a guide's ladder is
     * deterministic and would hand back the identical products, which is the
     * failure this whole action exists to prevent.
     */
    public static function reselect(): self
    {
        return new self(reselect: true);
    }

    /** Right products, wrong words. The shortlist survives; the prose does not. */
    public static function rewrite(): self
    {
        return new self(reselect: false);
    }
}
