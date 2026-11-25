<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Http\Controllers\SearchController;
use App\Services\Pages\Context\PageContext;
use App\Services\Seo\ResultTerms;

/**
 * The vocabulary of these results, each word linked to a narrower search.
 *
 * The same words as the chip row above the grid, available inside a sentence.
 * They are not a restatement of the query: they say what kind of thing this page
 * holds, mined from the titles that actually matched, and every one of them is a
 * real search that cannot dead-end in zero results.
 *
 * ## It will duplicate the chips if you let it
 *
 * The chip row still renders above the grid — it is a narrowing control, not
 * copy, and it is not part of the template. Putting `:term_links` in
 * `above_grid` therefore shows the same words twice, a few centimetres apart.
 * That is the editor's call and the region blurb warns about it; forbidding it
 * would also forbid the useful case, which is naming two or three of them inside
 * a sentence below the grid.
 */
final class TermLinks implements PlaceholderFunction
{
    public function name(): string
    {
        return 'term_links';
    }

    public function label(): string
    {
        return 'Words in these results, linked';
    }

    public function help(): string
    {
        return 'The vocabulary of the results, each word linked to a narrower search. Also shown as chips above the grid.';
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
        return Value::links([
            ['label' => 'draadloos', 'url' => '#'],
            ['label' => 'over-ear', 'url' => '#'],
            ['label' => 'ruisonderdrukking', 'url' => '#'],
        ]);
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function resolve(PageContext $context): Value
    {
        /*
         * No narrower links once the term has the chips' four words (2026-11-25), the
         * same stop as SearchController::PILLS_UNTIL. The chips got it on 2026-11-13 and
         * this block did not, so every page still linked six searches one word longer, up
         * to eight words: about 1,300 reachable pages per four-word search. A crawler on
         * residential proxies spent 83% of the search time after the 2026-11-24 deploy
         * on those 5-8 word pages (search-urls.md).
         */
        $term = trim((string) $context->fact('term'));

        if ($term !== '' && count(preg_split('/\s+/u', $term) ?: []) >= SearchController::PILLS_UNTIL) {
            return Value::links([]);
        }

        // Six rather than the chip row's eight: this is a sentence, and a
        // sentence with eight links in it is a list wearing a full stop.
        $terms = app(ResultTerms::class)->extract(
            $context->items,
            (string) $context->fact('term'),
            limit: 6,
        );

        return Value::links(array_map(fn (string $term) => [
            'label' => $context->narrowLabel($term),
            'url' => $context->narrowUrl($term),
        ], $terms));
    }
}
