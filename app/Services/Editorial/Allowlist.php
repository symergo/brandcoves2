<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\BrandStat;
use App\Models\Guide;
use App\Models\ProductGroup;
use Illuminate\Support\Collection;

/**
 * What a given piece of writing is allowed to link to.
 *
 * Four callers need this answer and they must agree: the Cove page, the guide
 * page, the edition builder handing a brief to the model, and the editorial API
 * telling an author which of their tokens survived. When they disagreed, the
 * API reported a link as fine and the page rendered it as plain text — the
 * worst possible split, because it is invisible from both ends.
 *
 * The guide list is the part that cannot be derived from the article itself, so
 * it is a query, and it is deliberately narrow: **published guides, in this
 * market, excluding the one being rendered**. A link to a draft is a 404 for a
 * reader and an indexed dead end for a crawler; a slug that exists in `be-nl`
 * need not exist in `es`; and an article linking to itself is a loop a reader
 * has to notice to escape.
 */
class Allowlist
{
    /**
     * The products, brands and categories a set of groups implies.
     *
     * @param  Collection<int, ProductGroup>|list<ProductGroup>  $groups
     * @return array{brands: list<string>, searches: list<string>, products: array<int, array{slug: string, title: string}>}
     */
    public function forProducts($groups): array
    {
        $groups = $groups instanceof Collection ? $groups : collect($groups);

        return [
            'brands' => $groups->pluck('brand')->filter()->unique()->values()->all(),
            'searches' => $groups->pluck('category')->filter()->unique()->values()->all(),
            'products' => $groups
                ->mapWithKeys(fn (ProductGroup $g) => [$g->id => ['slug' => $g->slug, 'title' => $g->title]])
                ->all(),
        ];
    }

    /**
     * Everything an article may link to.
     *
     * Wider than its own products in two directions, and both matter most for an
     * advice article — which has no products at all, and whose allowlist was
     * therefore empty. "How to shop for headphones" that cannot link to the
     * headphone search or to Sony is an article with nowhere to send anyone,
     * which is the one thing advice has to do.
     *
     * - **Brands**: every brand with a page in this market, not only the ones on
     *   this page. A brand page either exists or it does not, and `BrandStat`
     *   already knows which — that is a better allowlist than "whatever these
     *   seven products happen to be".
     * - **Searches**: the article's own declared queries, alongside its
     *   products' categories. An author who wrote `sourceQueries: ["koptelefoon"]`
     *   has already said what the piece is about; making that linkable needs no
     *   new field and cannot be used to link to something unconsidered.
     *
     * @param  Collection<int, ProductGroup>|list<ProductGroup>  $groups
     * @param  list<string>  $extraSearches  the article's own queries
     * @return array{brands: list<string>, searches: list<string>, products: array<int, array{slug: string, title: string}>, guides: list<string>}
     */
    public function full($groups, Market $market, ?int $excludeGuideId = null, array $extraSearches = []): array
    {
        $base = $this->forProducts($groups);

        return [
            'brands' => array_values(array_unique([
                ...$base['brands'],
                ...$this->brandsWithPages($market),
            ])),
            'searches' => array_values(array_unique(array_filter([
                ...$base['searches'],
                ...array_filter($extraSearches, 'is_string'),
            ]))),
            'products' => $base['products'],
            'guides' => $this->guideSlugs($market, $excludeGuideId),
        ];
    }

    /**
     * Brands this market has a page for.
     *
     * `pageworthy()` is the same gate the brand pages themselves use — three
     * products, because a page about a brand with one product on it is filler.
     * Linking to a brand below that bar would be linking to a 404.
     *
     * @return list<string>
     */
    public function brandsWithPages(Market $market): array
    {
        return BrandStat::query()
            ->forMarket($market)
            ->pageworthy()
            // Bounded for the same reason the guide list is: this is also handed
            // to a model as a prompt, where an unbounded list eats the context
            // the writing needs.
            ->orderByDesc('product_count')
            ->limit(300)
            ->pluck('brand')
            ->all();
    }

    /** @return list<string> */
    public function guideSlugs(Market $market, ?int $excludeGuideId = null): array
    {
        return Guide::query()
            ->where('market', $market->value)
            ->where('status', PublishStatus::Published->value)
            ->when($excludeGuideId !== null, fn ($q) => $q->where('id', '!=', $excludeGuideId))
            // Capped: an article cannot meaningfully link to a thousand guides,
            // and the list is also handed to a model as a prompt, where an
            // unbounded one would eat the context it needs for the writing.
            ->orderByDesc('published_at')
            ->limit(200)
            ->pluck('slug')
            ->all();
    }
}
