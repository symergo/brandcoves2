<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\IdentityKind;
use App\Models\ProductGroup;

/**
 * Of two products that are one, which survives a merge.
 *
 * A barcode product over a title one (its key is the one the site trusts),
 * then the one with more offers (fewer rows move, and it is likelier to be the
 * page people already link to), then the older one. Shared by the review
 * screen's one-at-a-time merge and the per-rule "The same" button, so both
 * choose the same way. See docs/features/match-review.md.
 */
final class MatchKeeper
{
    public static function pick(ProductGroup $a, ProductGroup $b): ProductGroup
    {
        $rank = fn (ProductGroup $g) => [
            $g->identity_kind === IdentityKind::Ean ? 0 : 1,
            -$g->offer_count,
            $g->id,
        ];

        return $rank($a) <= $rank($b) ? $a : $b;
    }
}
