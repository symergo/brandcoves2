<?php

declare(strict_types=1);

namespace App\Services\Cove\Selectors;

use App\Enums\CoveKind;

/**
 * Which selector a kind of Cove fills itself with.
 *
 * A `match` in one place rather than in the builder, the curation screen and the
 * redo action, all of which need the same answer and would otherwise each hold
 * their own copy of it.
 */
class Selectors
{
    public function __construct(
        private readonly SurpriseSelector $surprise,
        private readonly LadderSelector $ladder,
    ) {}

    public function for(CoveKind $kind): CoveSelector
    {
        return $kind->isArticle() ? $this->ladder : $this->surprise;
    }
}
