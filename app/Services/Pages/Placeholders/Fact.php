<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Services\Pages\Context\PageContext;

/**
 * The scalar case: a placeholder that reads one value off the page's facts.
 *
 * Most of them are this — `:term`, `:count`, `:low`, `:percent` — and writing
 * twelve near-identical classes would bury the three functions that actually do
 * something. One class, twelve instances, declared in {@see self::all()}.
 *
 * The facts themselves are computed once per request by the page's
 * `PageContext`, because they are read off the same result set the copy is
 * describing. That is the property that makes the copy publishable at all: every
 * number in a sentence is checkable against the grid immediately above it.
 */
final readonly class Fact implements PlaceholderFunction
{
    public function __construct(
        private string $name,
        private string $label,
        private string $help,
        private string $sample,
        private Absence $absent = Absence::BlankOrZero,
    ) {}

    /**
     * Every scalar placeholder the site knows, keyed by name.
     *
     * Samples are deliberately unround. "2.931" and "€ 1.299,00" show an editor
     * how a real sentence reads, where "10" and "€ 1,00" quietly flatter a line
     * that only scans well with small numbers in it.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        $facts = [
            new self('term', 'The search term', 'What the visitor typed.', 'koptelefoon', Absence::Never),
            new self('brand', 'The brand', 'The brand this page is about.', 'Sony', Absence::Never),
            new self('count', 'Total results', 'Every match, not only the ones on screen.', '2.931'),
            new self('shown', 'Products on this page', 'How many cards are visible.', '24'),
            new self('shops', 'Shop offers on this page', 'Offers summed across the visible products.', '61'),
            new self('comparable', 'Products sold by more than one shop', 'The ones that are a comparison on their own.', '14'),
            new self('reduced', 'Products below their 30-day median', 'Genuinely reduced, not marked down from a "from" price.', '137'),
            new self('percent', 'The biggest discount here', 'A whole number, without the sign.', '31'),
            new self('low', 'Cheapest price on this page', 'Formatted in the market currency.', '€ 19,99'),
            new self('high', 'Dearest price on this page', 'Formatted in the market currency.', '€ 1.299,00'),
            new self('brands', 'Brands present, as words', 'A plain list. Use :brand_links for a linked one.', 'Sony, Philips, JBL'),
            new self('shop', 'The leading shop', 'The one carrying most of this brand.', 'Coolblue'),
            new self('category', 'The leading category', 'What this brand mostly makes here.', 'Koptelefoons'),
            new self('categories', 'Categories, as words', 'What the brand appears in, most first.', 'Koptelefoons, speakers en soundbars'),
        ];

        $keyed = [];

        foreach ($facts as $fact) {
            $keyed[$fact->name()] = $fact;
        }

        return $keyed;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function help(): string
    {
        return $this->help;
    }

    public function level(): Level
    {
        return Level::Inline;
    }

    public function absent(): Absence
    {
        return $this->absent;
    }

    public function sample(): Value
    {
        return Value::text($this->sample);
    }

    public function dependsOn(): array
    {
        return [$this->name];
    }

    public function resolve(PageContext $context): Value
    {
        return Value::text($context->fact($this->name));
    }
}
