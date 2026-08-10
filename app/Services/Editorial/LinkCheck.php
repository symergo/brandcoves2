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
 * CoveMarkup strips anything outside the allowlist back to plain text, so a bad
 * token is never a broken link on the page — it is a silently unlinked phrase.
 * That is the right behaviour for a reader and a terrible experience for a
 * writer, who has no way to tell the difference between "this worked" and "this
 * quietly did nothing".
 *
 * So the write endpoints run the same renderer over the prose and hand back what
 * it rejected. The article still saves — a missing link is not a reason to
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
 *
 * A guide has no such caveat: its items are exactly what the author supplied.
 */
class LinkCheck
{
    public function __construct(
        private readonly CoveMarkup $markup,
        private readonly Allowlist $allowlist,
    ) {}

    /**
     * @param  Collection<int, ProductGroup>|list<ProductGroup>  $groups
     * @return array{links: int, unresolved: list<string>}
     */
    public function against(
        ?string $text,
        Market $market,
        $groups,
        ?int $excludeGuideId = null,
        array $extraSearches = [],
    ): array {
        return $this->all([$text], $market, $groups, $excludeGuideId, $extraSearches);
    }

    /**
     * The same check over several fields of one article.
     *
     * One call rather than one per field, because the allowlist costs a query
     * and a guide has an intro, a body and a sentence per item — eight
     * lookups of an identical list, otherwise.
     *
     * @param  list<string|null>  $texts
     * @param  Collection<int, ProductGroup>|list<ProductGroup>  $groups
     * @return array{links: int, unresolved: list<string>}
     */
    public function all(
        array $texts,
        Market $market,
        $groups,
        ?int $excludeGuideId = null,
        array $extraSearches = [],
    ): array {
        $texts = array_values(array_filter($texts, fn (?string $t) => filled($t)));

        if ($texts === []) {
            return ['links' => 0, 'unresolved' => []];
        }

        $allowed = $this->allowlist->full($groups, $market, $excludeGuideId, $extraSearches);
        $links = 0;
        $unresolved = [];

        foreach ($texts as $text) {
            $result = $this->markup->paragraphs((string) $text, $market, $allowed);
            $links += $result['links'];
            $unresolved = [...$unresolved, ...$result['rejected']];
        }

        return ['links' => $links, 'unresolved' => array_values(array_unique($unresolved))];
    }
}
