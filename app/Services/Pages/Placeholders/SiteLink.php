<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Services\Pages\Context\PageContext;

/**
 * A link to another part of the site, by name.
 *
 * ## Why an editor should not type the URL
 *
 * Every one of these is market-prefixed — `/be-nl/coves`, `/be-fr/coves` — so a
 * hand-typed path is right in one market and a 404 in four. Worse, it is a 404
 * nobody notices: the sentence still reads correctly, and the only symptom is a
 * dead link on thousands of pages in the markets nobody on the team browses.
 *
 * `:coves_link` resolves against the page's own market, so one block written
 * once is correct in all five.
 *
 * ## Why the label is not editable
 *
 * It comes from the language files — the same strings the navigation uses — so
 * the link inside a sentence says what the menu item says, in the reader's
 * language. An editor writing "see our [guides]" in a Dutch block would
 * otherwise have written a Dutch word into a French page's link, and the block
 * is per language anyway, so the freedom would buy nothing and cost consistency.
 *
 * ## Internal linking is the point
 *
 * A results page with no outbound links is a leaf, and a crawler that reaches a
 * leaf stops. These are the editorial half of that — links a person chose to put
 * in a sentence, as opposed to the term chips, which are generated.
 */
final readonly class SiteLink implements PlaceholderFunction
{
    /**
     * @param  string  $path  appended to the market prefix, so 'coves' → /be-nl/coves
     * @param  string  $translationKey  where the label comes from, per language
     */
    public function __construct(
        private string $name,
        private string $label,
        private string $path,
        private string $translationKey,
        private string $sample,
    ) {}

    /**
     * The pages worth linking to from a sentence.
     *
     * Deliberately not every route. A link is only worth an editor's sentence if
     * the destination is a place a reader might want next — so this is the
     * navigation, not the sitemap. Account pages, the quiz and the scanner are
     * left out: linking to them from copy on an indexable page is either
     * meaningless to a signed-out visitor or a dead end behind a login.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        /*
         * Labels reuse keys the site already renders somewhere.
         *
         * A new `site.links.*` block would be a second set of words for the same
         * places, and the two would drift the first time somebody renamed a menu
         * item — leaving a sentence linking to "Gidsen" from a navigation that
         * now says something else.
         */
        $links = [
            new self('search_link', 'Link to search', 'search', 'nav.search', 'Zoeken'),
            new self('brands_link', 'Link to the brand index', 'brands', 'brand.index_title', 'Merken'),
            new self('coves_link', 'Link to the Coves', 'coves', 'coves.title', 'Coves'),
            new self('guides_link', 'Link to the buying guides', 'guides', 'nav.guides', 'Gidsen'),
            new self('shops_link', 'Link to the shop directory', 'shops', 'shops.title', 'Winkels'),
            new self('gift_finder_link', 'Link to the gift finder', 'gift', 'nav.gift', 'Cadeauzoeker'),
            new self('search_help_link', 'Link to the search help', 'search-help', 'search_help.link', 'Waarop kun je hier zoeken?'),
        ];

        $keyed = [];

        foreach ($links as $link) {
            $keyed[$link->name()] = $link;
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
        return 'A link to that page, in the reader\'s market and language. The wording comes from the navigation.';
    }

    public function level(): Level
    {
        return Level::Inline;
    }

    public function absent(): Absence
    {
        // A site page always exists, so this can never hide a sentence.
        return Absence::Never;
    }

    public function sample(): Value
    {
        return Value::links([['label' => $this->sample, 'url' => '#']]);
    }

    public function dependsOn(): array
    {
        // A site page exists whatever is on the page linking to it.
        return [];
    }

    public function resolve(PageContext $context): Value
    {
        $label = __('site.'.$this->translationKey, [], $context->market->language());

        /*
         * A missing translation returns the key, which contains a dot.
         *
         * Rendering `nav.coves` into a sentence is worse than rendering nothing,
         * so this falls back to nothing and the sentence that named it
         * disappears — loudly enough to be noticed in review, quietly enough not
         * to publish a dotted path to a reader.
         */
        if (! is_string($label) || str_contains($label, '.')) {
            return Value::nothing();
        }

        return Value::links([[
            'label' => $label,
            'url' => '/'.$context->market->value.($this->path === '' ? '' : '/'.$this->path),
        ]]);
    }
}
