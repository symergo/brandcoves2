<?php

declare(strict_types=1);

namespace App\Services\Pages\Context;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Services\Pages\Placeholders\PlaceholderFunction;
use App\Services\Pages\Placeholders\Value;
use Illuminate\Support\Number;

/**
 * Everything a placeholder function or a condition may ask a page.
 *
 * ## Why a context and not a flat array of facts
 *
 * The old copy bank passed `['term' => …, 'count' => …]` — a snapshot of what
 * the caller happened to precompute. That is enough for scalars and nothing
 * else. `:brand_links` needs a URL builder and a market; `:related_searches`
 * needs to run a query. Handing a function the *page* rather than a snapshot of
 * it is what lets a function added next year need something nobody thought to
 * precompute today, which is the entire point of making placeholders extensible.
 *
 * ## The rule every fact here obeys
 *
 * Facts are read off **the products on this page**, never off the whole result
 * set. A reader can check a claim about twenty-four visible products and cannot
 * check one about four hundred they will never see. `:count` is the one
 * exception and it says so: it is the total, and it is only ever used to say how
 * many matches exist, not to characterise them.
 *
 * @property-read list<ProductGroup> $items
 */
abstract class PageContext
{
    /** @var array<string, string|int|null>|null */
    private ?array $facts = null;

    /** @var array<string, bool>|null */
    private ?array $conditions = null;

    /** @var array<string, Value> resolved placeholder values, one call each per request */
    private array $resolved = [];

    /**
     * @param  list<ProductGroup>  $items  the products actually on this page
     * @param  int  $total  every match, for `:count` alone
     * @param  string  $rotationKey  the page's identity — the term, the brand slug
     */
    public function __construct(
        public readonly Market $market,
        public readonly array $items,
        public readonly int $total,
        public readonly string $rotationKey,
    ) {}

    /** Which page's regions this context serves. */
    abstract public function page(): string;

    /**
     * Where a word from the results leads.
     *
     * Per page, because narrowing means different things: on a search page it
     * adds the word to the query, and on a brand page it sub-searches within the
     * brand. Getting this wrong is not a broken link, it is a link that quietly
     * leaves the page the reader was on — which is worse, because it looks like
     * it worked.
     */
    abstract public function narrowUrl(string $term): string;

    /**
     * The scalar facts this page can state, keyed by placeholder name.
     *
     * @return array<string, string|int|null>
     */
    abstract protected function computeFacts(): array;

    /**
     * Whether each named condition holds.
     *
     * @return array<string, bool>
     */
    abstract protected function computeConditions(): array;

    public function fact(string $name): string|int|null
    {
        return $this->facts()[$name] ?? null;
    }

    /** @return array<string, string|int|null> */
    public function facts(): array
    {
        return $this->facts ??= $this->computeFacts();
    }

    /**
     * Does this named condition hold on this page?
     *
     * An unknown key is **false**, never true. A condition ticked on a block and
     * then renamed in code has to fail closed: a block whose gate has vanished
     * must stop rendering, not start rendering unconditionally on every page in
     * the market.
     */
    public function condition(string $key): bool
    {
        return $this->conditions()[$key] ?? false;
    }

    /** @return array<string, bool> */
    public function conditions(): array
    {
        return $this->conditions ??= $this->computeConditions();
    }

    /**
     * Resolve a placeholder once per request.
     *
     * Two blocks naming `:brand_links` cost one call, and a function nobody
     * named costs none — which matters, because one of them is a database query
     * and resolving it eagerly for a page whose block was switched off would be
     * work with no reader.
     */
    public function resolve(PlaceholderFunction $function): Value
    {
        return $this->resolved[$function->name()] ??= $function->resolve($this);
    }

    /** Prices in cents, from the products on this page. */
    protected function prices(): array
    {
        return array_values(array_filter(array_map(
            fn (ProductGroup $group) => $group->min_price,
            $this->items,
        )));
    }

    /** Discount percentages, one per genuinely reduced product. */
    protected function discounts(): array
    {
        return array_values(array_filter(array_map(
            fn (ProductGroup $group) => $group->discountPercent(),
            $this->items,
        )));
    }

    /** Products a shopper could compare, because more than one shop sells them. */
    protected function comparable(): int
    {
        return count(array_filter($this->items, fn (ProductGroup $g) => $g->merchant_count > 1));
    }

    /** Offers summed across the visible products. */
    protected function shops(): int
    {
        return array_sum(array_map(fn (ProductGroup $g) => max(1, (int) $g->merchant_count), $this->items));
    }

    /**
     * The brands present, deduplicated case-insensitively but keeping the
     * spelling the feed used.
     *
     * @return list<string>
     */
    protected function brands(): array
    {
        $brands = [];

        foreach ($this->items as $group) {
            if ($group->brand !== null && trim($group->brand) !== '') {
                $brands[mb_strtolower($group->brand)] = $group->brand;
            }
        }

        return array_values($brands);
    }

    protected function money(int $cents): string
    {
        return Number::currency($cents / 100, $this->market->currency(), $this->market->hrefLang());
    }

    protected function number(int $value): string
    {
        return Number::format($value, locale: $this->market->hrefLang());
    }

    /**
     * "a, b and c", in the market's language.
     *
     * @param  list<string>  $items
     */
    protected function list(array $items): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).' '.__('site.brand.and', [], $this->market->language()).' '.$last;
    }
}
