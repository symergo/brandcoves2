<?php

declare(strict_types=1);

namespace App\Services\Pages;

use App\Models\PageBlock;
use App\Services\Pages\Placeholders\Value;

/**
 * A flat ordered list of blocks becomes sections.
 *
 * A heading opens a section; the paragraphs after it belong to it. That is the
 * whole rule, and it is why there are two block kinds rather than a tree: an
 * editor arranges a document by ordering a list, which is one integer to store
 * and two buttons to move, instead of a nesting to maintain.
 *
 * ## A section with no surviving paragraphs is dropped
 *
 * Which fixes something that predates all of this. A heading is what a section
 * *is* — the markup keys on it, a reader navigates by it — so a heading standing
 * over nothing is not a shorter page, it is a broken one. It happened before
 * whenever every paragraph under a heading was guarded out by the page's own
 * facts, and it will happen far more often now that conditions are a checkbox.
 */
final class BlockSections
{
    /**
     * @param  list<array{kind: string, parts: list<array<string, mixed>>}>  $blocks
     * @return list<array{heading: string, body: list<list<array<string, mixed>>>}>
     */
    public static function assemble(array $blocks): array
    {
        $sections = [];
        $current = null;

        foreach ($blocks as $block) {
            if ($block['kind'] === PageBlock::HEADING) {
                if ($current !== null) {
                    $sections[] = $current;
                }

                $current = ['heading' => self::flatten($block['parts']), 'body' => []];

                continue;
            }

            /*
             * Paragraphs before the first heading are a section of their own,
             * with no title. Common in `above_grid` and the empty state, where a
             * heading would be overwrought — and the renderer simply omits the
             * <h2>.
             */
            $current ??= ['heading' => '', 'body' => []];
            $current['body'][] = $block['parts'];
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        return array_values(array_filter($sections, fn (array $s) => $s['body'] !== []));
    }

    /**
     * A heading is text, so its parts collapse to a string.
     *
     * Anything else has already been refused twice — by the admin, and by
     * `PageCopy::available()` — so a non-text part here would be a bug rather
     * than an editor's choice, and dropping it quietly is the right failure.
     *
     * @param  list<array<string, mixed>>  $parts
     */
    private static function flatten(array $parts): string
    {
        $text = '';

        foreach ($parts as $part) {
            if (($part['t'] ?? null) === Value::TEXT) {
                $text .= $part['v'];
            }
        }

        return trim($text);
    }
}
