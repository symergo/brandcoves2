<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Services\Pages\Context\PageContext;

/**
 * A `:name` an editor can put in a block, and the code that answers it.
 *
 * ## Why a function and not an array key
 *
 * The old copy bank interpolated from a flat `['term' => …, 'count' => …]` bag
 * assembled by the caller. That works exactly as long as every placeholder is a
 * scalar somebody already computed. It has no answer for "the brands in these
 * results, each linked to its brand page" — that needs a service, a URL builder
 * and a market — and no answer at all for "the searches people ran next", which
 * is a trigram query.
 *
 * Making a placeholder a class moves the knowledge to where the knowledge is.
 * Adding one later is this interface, one line in {@see PlaceholderRegistry},
 * and its name in whichever regions offer it. No migration, no schema change, no
 * admin change — it appears in the editor's palette because the palette is
 * rendered from the registry — and every block already written can use it the
 * day it ships.
 *
 * ## Resolved lazily, and at most once
 *
 * `resolve()` is called only when a block that survived its conditions actually
 * names this function, and its result is memoised on the context. Otherwise
 * `:related_searches` would run its query on every page that has the block
 * switched off.
 */
interface PlaceholderFunction
{
    /** The token, without the colon: `term`, `brand_links`, `related_searches`. */
    public function name(): string;

    /** What the admin palette calls it. */
    public function label(): string;

    /** One line on what it produces, shown in the palette. */
    public function help(): string;

    /** Inside a sentence, or a paragraph of its own. */
    public function level(): Level;

    /** When an unanswerable value should hide the text that names it. */
    public function absent(): Absence;

    /** A plausible value, for the admin preview. Never touches the database. */
    public function sample(): Value;

    /**
     * Facts this function cannot work without.
     *
     * Declared so the disagreement can be *tested* rather than discovered. A
     * region offering `:brand_page_link` on a page whose context has no brand
     * does not render `:brand_page_link` to a reader — it silently hides every
     * block that mentions it, on every page, for ever, and nothing reports it.
     * `PageRegionsTest` checks each of these against the page's own facts.
     *
     * Empty for a function that needs no fact at all, like a link to a fixed
     * page.
     *
     * @return list<string>
     */
    public function dependsOn(): array;

    public function resolve(PageContext $context): Value;
}
