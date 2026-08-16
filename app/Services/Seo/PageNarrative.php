<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\SearchLog;
use Illuminate\Support\Number;

/**
 * The long-form copy below a results grid or a brand page.
 *
 * ## Why a results page needs several hundred words
 *
 * A grid of cards is almost pure markup. Strip the prices and titles and there
 * is nothing left for a search engine to decide what the page is *about* — which
 * is why comparison sites rank for their guides and not for their listings. The
 * short intro above the grid states the page's facts; this states what the page
 * *is*, in enough words to be legible as a document rather than a template.
 *
 * ## The line this must not cross
 *
 * There is an obvious way to hit a word count: repeat the query with filler
 * around it. *"Looking for the best :term? We have a wide selection of :term at
 * great prices. Compare :term today!"* It works for about a fortnight, and then
 * a helpful-content update decides the domain is mostly padding and takes the
 * pages that were actually good down with it.
 *
 * So every section here is one of two things:
 *
 *  1. **A fact about this page** — counts, price range, how many shops, how many
 *     are reduced, what the vocabulary of the results is.
 *  2. **A true explanation of how the site works**, written once and worth
 *     reading — what the median means, why an offer count matters, what a
 *     comparison across shops does that a single shop's listing cannot.
 *
 * The second kind is identical across pages, which is fine and deliberate: it is
 * boilerplate in the honest sense, the way a shipping policy is. What must never
 * be identical across pages is the *first* kind, and it cannot be, because it is
 * read off the results.
 *
 * ## Below the grid, never above it
 *
 * A shopper came for products. Three hundred words between them and the first
 * card is a worse page for a human, and Google has been explicit for years that
 * it is a worse page for them too. The short factual intro goes above; this goes
 * after.
 */
class PageNarrative
{
    /**
     * Sections for a keyword search.
     *
     * @param  list<ProductGroup>  $items
     * @param  list<string>  $terms  vocabulary extracted from the results
     * @return array{sections: list<array{heading: string, body: list<string>}>, faq: list<array{q: string, a: string}>, related: list<array{term: string, url: string}>}
     */
    public function forSearch(string $query, array $items, Market $market, int $total): array
    {
        $facts = $this->facts($items, $market);
        $replace = ['term' => $query, ...$facts['replace'], 'count' => Number::format($total, locale: $market->hrefLang())];

        $sections = [
            [
                'heading' => $this->line('narrative.compare_heading', $market, $replace),
                'body' => array_values(array_filter([
                    $this->line('narrative.compare_1', $market, $replace),
                    $facts['comparable'] > 0 ? $this->line('narrative.compare_2', $market, $replace) : null,
                    $this->line('narrative.compare_3', $market, $replace),
                ])),
            ],
            [
                'heading' => $this->line('narrative.prices_heading', $market, $replace),
                'body' => array_values(array_filter([
                    $facts['hasPrices'] ? $this->line('narrative.prices_1', $market, $replace) : null,
                    $this->line('narrative.prices_2', $market, $replace),
                    $facts['reduced'] > 0 ? $this->line('narrative.prices_3', $market, $replace) : null,
                ])),
            ],
            [
                'heading' => $this->line('narrative.choosing_heading', $market, $replace),
                'body' => array_values(array_filter([
                    $this->line('narrative.choosing_1', $market, $replace),
                    $this->line('narrative.choosing_2', $market, $replace),
                    $facts['brands'] !== [] ? $this->line('narrative.choosing_3', $market, [
                        ...$replace,
                        'brands' => implode(', ', array_slice($facts['brands'], 0, 6)),
                    ]) : null,
                ])),
            ],
        ];

        return [
            'sections' => $sections,
            'faq' => $this->faq($market, $replace, $facts),
            'related' => $this->related($query, $market),
        ];
    }

    /**
     * Sections for a brand page.
     *
     * The reader typed a brand name, so the first section is about the brand:
     * what it makes here, and what that says about it in this market. The
     * comparison mechanics that used to open the page are true and were never
     * the question — they now come after, where they answer "how do I choose
     * one" rather than standing in for "what is this".
     *
     * @param  list<ProductGroup>  $items
     * @param  list<string>  $categories  what the brand makes here, most first
     * @return array{sections: list<array{heading: string, body: list<string>}>, faq: list<array{q: string, a: string}>, related: list<array{term: string, url: string}>}
     */
    public function forBrand(
        string $brand,
        array $items,
        Market $market,
        int $total,
        ?string $topMerchant,
        ?string $category,
        array $categories = [],
    ): array {
        $facts = $this->facts($items, $market);
        $replace = [
            'brand' => $brand,
            'shop' => $topMerchant ?? '',
            'category' => $category ?? '',
            'categories' => $this->list(array_slice($categories, 0, 3), $market),
            'count' => Number::format($total, locale: $market->hrefLang()),
            ...$facts['replace'],
        ];

        $sections = [
            [
                'heading' => $this->line('brand_narrative.about_heading', $market, $replace),
                'body' => array_values(array_filter([
                    $categories !== [] ? $this->line('brand_narrative.about_1', $market, $replace) : null,
                    $category !== null ? $this->line('brand_narrative.about_2', $market, $replace) : null,
                    $this->line('brand_narrative.about_3', $market, $replace),
                ])),
            ],
            [
                'heading' => $this->line('brand_narrative.stocked_heading', $market, $replace),
                'body' => array_values(array_filter([
                    $topMerchant !== null ? $this->line('brand_narrative.stocked_1', $market, $replace) : null,
                    $facts['comparable'] > 0 ? $this->line('brand_narrative.stocked_2', $market, $replace) : null,
                ])),
            ],
            [
                'heading' => $this->line('brand_narrative.choosing_heading', $market, $replace),
                'body' => array_values(array_filter([
                    $this->line('brand_narrative.choosing_1', $market, $replace),
                    $facts['hasPrices'] ? $this->line('brand_narrative.choosing_2', $market, $replace) : null,
                    $this->line('brand_narrative.choosing_3', $market, $replace),
                ])),
            ],
        ];

        return [
            'sections' => $sections,
            'faq' => $this->brandFaq($market, $replace, $facts),
            'related' => $this->related($brand, $market),
        ];
    }

    /**
     * "a, b and c", in the market's language.
     *
     * @param  list<string>  $items
     */
    private function list(array $items, Market $market): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).' '.__('site.brand.and', [], $market->language()).' '.$last;
    }

    /**
     * Everything the copy is allowed to assert, read off the results on the page.
     *
     * Read off *this page's* items rather than the whole result set on purpose: a
     * reader can check a claim about twenty-four visible products and cannot check
     * one about four hundred they will never see.
     *
     * @param  list<ProductGroup>  $items
     * @return array{replace: array<string, string|int>, comparable: int, reduced: int, hasPrices: bool, brands: list<string>}
     */
    private function facts(array $items, Market $market): array
    {
        $prices = array_values(array_filter(array_map(fn (ProductGroup $g) => $g->min_price, $items)));
        $reduced = array_values(array_filter(array_map(fn (ProductGroup $g) => $g->discountPercent(), $items)));
        $comparable = count(array_filter($items, fn (ProductGroup $g) => $g->merchant_count > 1));
        $shops = array_sum(array_map(fn (ProductGroup $g) => max(1, (int) $g->merchant_count), $items));

        $brands = [];

        foreach ($items as $group) {
            if ($group->brand !== null && trim($group->brand) !== '') {
                $brands[mb_strtolower($group->brand)] = $group->brand;
            }
        }

        return [
            'replace' => [
                'low' => $prices === [] ? '' : $this->money(min($prices), $market),
                'high' => $prices === [] ? '' : $this->money(max($prices), $market),
                'shops' => $shops,
                'comparable' => $comparable,
                'reduced' => count($reduced),
                'percent' => $reduced === [] ? 0 : max($reduced),
                'shown' => count($items),
            ],
            'comparable' => $comparable,
            'reduced' => count($reduced),
            'hasPrices' => $prices !== [],
            'brands' => array_values($brands),
        ];
    }

    /**
     * Three questions, answered from this page's own numbers.
     *
     * Rendered as `FAQPage` JSON-LD as well as visible text. Both halves are
     * required: structured data whose answer is not on the page is a
     * misrepresentation, and search engines have started treating it as one.
     *
     * @param  array<string, string|int>  $replace
     * @param  array{comparable: int, reduced: int, hasPrices: bool, brands: list<string>}  $facts
     * @return list<array{q: string, a: string}>
     */
    private function faq(Market $market, array $replace, array $facts): array
    {
        $faq = [];

        if ($facts['hasPrices']) {
            $faq[] = [
                'q' => $this->line('narrative.faq_price_q', $market, $replace),
                'a' => $this->line('narrative.faq_price_a', $market, $replace),
            ];
        }

        $faq[] = [
            'q' => $this->line('narrative.faq_where_q', $market, $replace),
            'a' => $this->line('narrative.faq_where_a', $market, $replace),
        ];

        $faq[] = [
            'q' => $this->line('narrative.faq_fresh_q', $market, $replace),
            'a' => $this->line('narrative.faq_fresh_a', $market, $replace),
        ];

        return $faq;
    }

    /**
     * @param  array<string, string|int>  $replace
     * @param  array{comparable: int, reduced: int, hasPrices: bool, brands: list<string>}  $facts
     * @return list<array{q: string, a: string}>
     */
    private function brandFaq(Market $market, array $replace, array $facts): array
    {
        $faq = [];

        if ($facts['hasPrices']) {
            $faq[] = [
                'q' => $this->line('brand_narrative.faq_price_q', $market, $replace),
                'a' => $this->line('brand_narrative.faq_price_a', $market, $replace),
            ];
        }

        $faq[] = [
            'q' => $this->line('brand_narrative.faq_where_q', $market, $replace),
            'a' => $this->line('brand_narrative.faq_where_a', $market, $replace),
        ];

        if ($facts['reduced'] > 0) {
            $faq[] = [
                'q' => $this->line('brand_narrative.faq_discount_q', $market, $replace),
                'a' => $this->line('brand_narrative.faq_discount_a', $market, $replace),
            ];
        }

        return $faq;
    }

    /**
     * Other searches people ran here.
     *
     * The internal-linking half of the job, and the only part of this class that
     * is not about the current page. A results page with no outbound links is a
     * leaf; a crawler that reaches a leaf stops.
     *
     * From our own log, so these are real searches with real results rather than
     * a keyword tool's guesses — and they are the demand signal no competitor can
     * see. Trigram similarity finds the neighbours: "koptelefoon" pulls
     * "draadloze koptelefoon" and "gaming koptelefoon" without a taxonomy.
     *
     * @return list<array{term: string, url: string}>
     */
    private function related(string $term, Market $market): array
    {
        $needle = trim(mb_strtolower($term));

        if ($needle === '') {
            return [];
        }

        $rows = SearchLog::query()
            ->where('market', $market->value)
            ->where('hour_bucket', '>=', now()->subDays(90))
            ->whereRaw('lower(query) <> ?', [$needle])
            // `<%` (word_similarity), never `%`. Measured on this catalogue:
            // similarity() compares whole strings and scores a realistic
            // neighbour under the 0.3 default, so `%` finds nothing.
            ->whereRaw('? <% query', [$needle])
            /*
             * Held at 0.6 explicitly. **Added 2026-08-16.**
             *
             * `<%` compares against pg_trgm.word_similarity_threshold, which
             * search now sets to 0.45 on every connection so its own `<%` can
             * use the trigram index. These chips were written against Postgres'
             * 0.6 default and would have widened as a side effect.
             *
             * Wrong here for the same reason as in SpectrumRetriever, and more
             * publicly: these are rendered as related searches on an indexable
             * page. A loose neighbour is not a forgiving typo, it is a link
             * promising something the target page does not answer.
             */
            ->whereRaw('word_similarity(?, query) >= ?', [
                $needle,
                (float) config('giftcoves.search.trigram_threshold_strict'),
            ])
            ->where('result_count', '>', 0)
            ->groupBy('query')
            ->orderByRaw('sum(search_count) desc')
            ->limit(8)
            ->pluck('query');

        return $rows
            ->map(fn (string $query) => [
                'term' => $query,
                'url' => '/'.$market->value.'/search?'.http_build_query(['q' => $query]),
            ])
            ->values()
            ->all();
    }

    /**
     * One line, drawn from its editable variants.
     *
     * `$key` is still `namespace.slot` so the three dozen call sites did not have
     * to change; the namespace maps to a surface and the rotation is `CopyBank`'s
     * business.
     *
     * The rotation key is read out of `$replace` rather than threaded through as
     * a parameter, because it is already there: the page's identity is `:term` on
     * a search page and `:brand` on a brand page, and those are exactly the
     * values the copy interpolates. Passing it separately would create a second
     * source of truth that could disagree with the first.
     *
     * @param  array<string, string|int|null>  $replace
     */
    private function line(string $key, Market $market, array $replace): string
    {
        [$namespace, $slot] = explode('.', $key, 2);

        $surface = $namespace === 'brand_narrative' ? 'brand' : 'search';
        $rotationKey = (string) ($replace['brand'] ?? $replace['term'] ?? '');

        return app(CopyBank::class)->line($surface, $slot, $market, $replace, $rotationKey);
    }

    private function money(int $cents, Market $market): string
    {
        return Number::currency($cents / 100, $market->currency(), $market->hrefLang());
    }
}
