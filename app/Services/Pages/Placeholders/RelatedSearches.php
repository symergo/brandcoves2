<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Services\Pages\Context\PageContext;
use App\Services\Seo\RelatedSearchQuery;

/**
 * What people searched next — the "Verwante zoekopdrachten" block, as content.
 *
 * ## Why this is a placeholder rather than a fixed part of the page
 *
 * It used to be markup at the bottom of `PageNarrative.tsx`, with its heading
 * and its intro read from the language files. That made it the one thing on the
 * page an editor could see and not touch: not movable, not retitlable, not
 * removable, and invisible to the screen where they edit everything else.
 *
 * As a placeholder it is ordinary content. The heading is a heading block, the
 * intro is a paragraph, and the chips are a paragraph holding nothing but this —
 * so an editor can put it above the questions, rename it, or take it out, and
 * the seeding migration reproduces exactly the arrangement the page has today.
 *
 * ## Block level, so it must be alone in its paragraph
 *
 * It draws a row of pills, which cannot legally nest inside a `<p>`. Validation
 * says so in the admin and the renderer enforces it again.
 *
 * ## It runs a query, so it runs only when something will render it
 *
 * A trigram scan over ninety days of `search_logs`. `PageContext::resolve()`
 * memoises it, and `PageCopy` never asks unless a surviving block names it — so
 * switching the block off costs nothing rather than costing a query whose result
 * is thrown away.
 */
final class RelatedSearches implements PlaceholderFunction
{
    public function name(): string
    {
        return 'related_searches';
    }

    public function label(): string
    {
        return 'Related searches';
    }

    public function help(): string
    {
        return 'A row of links to what people searched next, from our own log. Put it in a paragraph of its own.';
    }

    public function level(): Level
    {
        return Level::Block;
    }

    public function absent(): Absence
    {
        return Absence::Blank;
    }

    public function sample(): Value
    {
        return Value::chips([
            ['label' => 'draadloze koptelefoon', 'url' => '#'],
            ['label' => 'gaming koptelefoon', 'url' => '#'],
            ['label' => 'koptelefoon met noise cancelling', 'url' => '#'],
        ]);
    }

    public function dependsOn(): array
    {
        // Seeded from the rotation key, which every page has by definition.
        return [];
    }

    public function resolve(PageContext $context): Value
    {
        /*
         * Seeded from the page's own identity — the term on a search page, the
         * brand on a brand page. A brand page asking "what else did people
         * search for near 'Sony'" is the same useful question as a search page
         * asking it about its query.
         */
        return Value::chips(
            app(RelatedSearchQuery::class)->for($context->rotationKey, $context->market),
        );
    }
}
