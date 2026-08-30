<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Enums\Market;
use App\Models\BrandStat;
use App\Services\Pages\Context\BrandContext;
use App\Services\Pages\Context\PageContext;
use App\Services\Pages\Context\SearchContext;

/**
 * What this page is about, as a link to the canonical page for it.
 *
 * `:term` and `:brand` say the words. These say the words *and take a reader
 * somewhere* — `:term_page_link` to the clean search for that term,
 * `:brand_page_link` to that brand's own page.
 *
 * ## The useful case is not the page you are on
 *
 * On a brand page `:brand_page_link` would link to itself, which is why it does
 * not: a self-link is noise for a reader and a wasted signal for a crawler, so
 * it degrades to plain text there. The same for `:term_page_link` on the
 * canonical search page.
 *
 * Where they earn their place is everywhere else the same block can reach:
 *
 *  - a **brand page** mentioning the search for its category;
 *  - a **search page** naming the brand it is mostly about;
 *  - an **empty state**, where "nothing for X" should offer the clean search for
 *    X rather than leave a dead end;
 *  - and every page a region is added to later, which is the point of writing
 *    the block once.
 *
 * ## Only when the destination exists
 *
 * `:brand_page_link` resolves through `BrandStat`, which knows whether a brand
 * has a page at all — a brand needs three products before it earns one, because
 * a page about a brand with one product on it is filler. A brand with no page
 * renders as plain text rather than as a link to a 404, and a link to a 404 in
 * a sentence on every search page is the worst possible place for one.
 */
final readonly class SubjectLink implements PlaceholderFunction
{
    public const TERM = 'term_page_link';

    public const BRAND = 'brand_page_link';

    public function __construct(private string $name) {}

    /** @return array<string, self> */
    public static function all(): array
    {
        return [
            self::TERM => new self(self::TERM),
            self::BRAND => new self(self::BRAND),
        ];
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->name === self::TERM
            ? 'The search term, linked'
            : 'The brand, linked to its page';
    }

    public function help(): string
    {
        return $this->name === self::TERM
            ? 'The words the visitor searched for, linked to the clean search for them. Plain text on the search page itself.'
            : 'The brand name, linked to its brand page. Plain text where the brand has no page, or on that page itself.';
    }

    public function level(): Level
    {
        return Level::Inline;
    }

    public function absent(): Absence
    {
        // The subject of a page always exists — the guard on every region that
        // offers this already promises it. What varies is whether it is a link.
        return Absence::Blank;
    }

    public function sample(): Value
    {
        return Value::links([[
            'label' => $this->name === self::TERM ? 'koptelefoon' : 'Sony',
            'url' => '#',
        ]]);
    }

    public function dependsOn(): array
    {
        return [$this->name === self::TERM ? 'term' : 'brand'];
    }

    public function resolve(PageContext $context): Value
    {
        return $this->name === self::TERM
            ? $this->term($context)
            : $this->brand($context);
    }

    private function term(PageContext $context): Value
    {
        $term = trim((string) $context->fact('term'));

        if ($term === '') {
            return Value::nothing();
        }

        // Already on the clean search for this term: say it, do not link it.
        if ($context instanceof SearchContext) {
            return Value::text($term);
        }

        return Value::links([[
            'label' => $term,
            'url' => '/'.$context->market->value.'/search?'.http_build_query(['q' => $term]),
        ]]);
    }

    private function brand(PageContext $context): Value
    {
        $brand = trim((string) $context->fact('brand'));

        if ($brand === '') {
            return Value::nothing();
        }

        if ($context instanceof BrandContext) {
            return Value::text($brand);
        }

        $url = $this->brandPage($brand, $context->market);

        return $url === null
            ? Value::text($brand)
            : Value::links([['label' => $brand, 'url' => $url]]);
    }

    /**
     * The brand's page, if it has earned one.
     *
     * Through `BrandStat` rather than `Str::slug()` at the call site, for the two
     * reasons `BrandLinker` spells out: not every brand has a page, and the slug
     * has to be the stored one — recomputing it agrees today and stops agreeing
     * the moment a feed renames a brand.
     */
    private function brandPage(string $brand, Market $market): ?string
    {
        $stat = BrandStat::query()
            ->where('market', $market->value)
            ->where('brand', $brand)
            ->pageworthy()
            ->first(['slug']);

        return $stat?->slug === null ? null : '/'.$market->value.'/brand/'.$stat->slug;
    }
}
