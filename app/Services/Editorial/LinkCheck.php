<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Services\Guides\CoveMarkup;
use Illuminate\Support\Collection;

/**
 * Tells an author which of their link tokens will survive rendering.
 *
 * CoveMarkup already strips anything outside the allowlist back to plain text,
 * so a bad token is never a broken link on the page — it is a silently
 * unlinked phrase. That is the right behaviour for a reader and a terrible
 * experience for a writer, who has no way to tell the difference between "this
 * worked" and "this quietly did nothing".
 *
 * So the write endpoints run the same renderer over the prose and hand back
 * what it rejected. The article still saves — a missing link is not a reason to
 * refuse a piece of writing — but the author learns immediately instead of by
 * reading the published page.
 *
 * ## The honest caveat
 *
 * For a Cove plan the check is advisory. The final allowlist includes the finds
 * the Serendipity Engine picks at build time, which do not exist yet when the
 * plan is written, so a token naming a product that is not pinned may still
 * resolve later. It is reported as unresolved because that is what is *known*
 * now, and telling an author a link is fine when it might not be is the failure
 * that matters.
 */
class LinkCheck
{
    public function __construct(private readonly CoveMarkup $markup) {}

    /**
     * @param  Collection<int, ProductGroup>|list<ProductGroup>  $groups
     * @return array{links: int, unresolved: list<string>}
     */
    public function against(?string $text, Market $market, $groups): array
    {
        if (blank($text)) {
            return ['links' => 0, 'unresolved' => []];
        }

        $groups = $groups instanceof Collection ? $groups : collect($groups);

        $result = $this->markup->paragraphs((string) $text, $market, $this->allowlist($groups));

        return [
            'links' => $result['links'],
            'unresolved' => array_values(array_unique($result['rejected'])),
        ];
    }

    /**
     * The allowlist a set of products implies.
     *
     * Same shape the edition builder and the Cove page both construct: the
     * products themselves, the brands behind them and their categories as
     * searchable phrases. Kept here so the three do not drift into disagreeing
     * about what a writer is allowed to link to.
     *
     * @param  Collection<int, ProductGroup>  $groups
     * @return array{brands: list<string>, searches: list<string>, products: array<int, array{slug: string, title: string}>}
     */
    public function allowlist(Collection $groups): array
    {
        return [
            'brands' => $groups->pluck('brand')->filter()->unique()->values()->all(),
            'searches' => $groups->pluck('category')->filter()->unique()->values()->all(),
            'products' => $groups
                ->mapWithKeys(fn (ProductGroup $g) => [$g->id => ['slug' => $g->slug, 'title' => $g->title]])
                ->all(),
        ];
    }
}
