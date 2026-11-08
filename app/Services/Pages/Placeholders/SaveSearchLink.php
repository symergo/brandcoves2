<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Services\Pages\Context\PageContext;
use App\Support\SearchUrl;

/**
 * "Bewaar :term op je cadeaulijst": saves this search to a list (owner,
 * 2026-11-08: "make the latter a link that allows to save the search to a
 * wishlist").
 *
 * Not a link but the site's Save, drawn as words: the same list choice,
 * sign-in when signed out and confirmation with Undo as every other save
 * (`SaveToList`). What is saved is a hand-written item called "Lenovo
 * aanbiedingen" (`site.search_copy.saved_title`), carrying this search's
 * address, so whoever opens the list lands on the same
 * comparison. ItemSaver does not read that page, as it does a shop's: it is
 * ours.
 *
 * The words come from `site.search_copy.save_search` in the page's language,
 * so a block writes `:save_search_link` and nothing else.
 */
final class SaveSearchLink implements PlaceholderFunction
{
    public function name(): string
    {
        return 'save_search_link';
    }

    public function label(): string
    {
        return 'Save this search to a list';
    }

    public function help(): string
    {
        return 'The words "Bewaar :term op je cadeaulijst" in the page\'s language, as a Save button: it puts this search on one of the visitor\'s lists.';
    }

    public function level(): Level
    {
        return Level::Inline;
    }

    public function absent(): Absence
    {
        return Absence::Blank;
    }

    public function sample(): Value
    {
        return Value::save('Bewaar koptelefoon op je cadeaulijst', 'Koptelefoon', '#');
    }

    public function dependsOn(): array
    {
        return ['term'];
    }

    public function resolve(PageContext $context): Value
    {
        $term = trim((string) $context->fact('term'));

        if ($term === '') {
            return Value::nothing();
        }

        return Value::save(
            __('site.search_copy.save_search', ['term' => $term], $context->market->language()),
            // "Lenovo aanbiedingen" (owner, 2026-11-08): what the list
            // shows, and the link goes to these results.
            self::capitalised(__('site.search_copy.saved_title', ['term' => $term], $context->market->language())),
            url(SearchUrl::for($context->market, $term)),
        );
    }

    private static function capitalised(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
