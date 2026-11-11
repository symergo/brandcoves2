<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Enums\Market;
use App\Services\Guides\CoveMarkup;
use App\Services\Seo\BrandLinker;

/**
 * A CoveMarkup whose brand linker answers from memory, for CoveMarkupTest and
 * ProseCardsTest.
 *
 * Those tests are about token parsing and destination allowlisting, neither of
 * which needs a database. `CoveMarkup` used to resolve `BrandLinker` from the
 * container mid-render, which made every case depend on a `brand_stats` table,
 * so the file passed on its own and errored on all 11 cases in a full run,
 * depending on what an earlier test had left behind.
 *
 * Empty is the honest answer for a class with no brand pages: `brand()` falls
 * back to a filtered search link, which is exactly what those tests assert.
 * Whether a brand *has* a page belongs to BrandPageTest.
 */
trait MarkupWithoutBrandPages
{
    private function markupWithoutBrandPages(): CoveMarkup
    {
        return new CoveMarkup(new class extends BrandLinker
        {
            public function urls(array $brands, Market $market): array
            {
                return [];
            }
        });
    }
}
