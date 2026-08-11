<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use Illuminate\Support\Str;

/**
 * A merchant description, made safe to put on a card.
 *
 * Feed descriptions are the least disciplined field in the catalogue. The same
 * column arrives as clean prose, as an HTML fragment with `<ul>` and `<br />`,
 * as a run of pipe-separated spec bullets, as the title repeated, and as the
 * empty string dressed up as a space. Nothing downstream may assume any of it,
 * which is why this is a pure function with its own test rather than a
 * `Str::limit()` at the call site.
 */
class Excerpt
{
    /**
     * Below this a description is not a sentence, it is a scrap.
     *
     * Measured against real Awin rows: "Zwart", "One size", "Nieuw" and the
     * brand name alone all appear in this column. Rendering those under a
     * product title looks like a bug, and an absent line looks like nothing,
     * so the short ones are dropped rather than shown.
     */
    private const MINIMUM = 30;

    /** Roughly three lines on a card at the width these grids use. */
    private const LIMIT = 180;

    public static function make(?string $raw, int $limit = self::LIMIT): ?string
    {
        if ($raw === null) {
            return null;
        }

        /*
         * Block-ish tags become a space before tags are stripped.
         *
         * `strip_tags()` alone concatenates across them, so
         * "<li>Bluetooth</li><li>ANC</li>" collapses to "BluetoothANC" — two
         * real words welded into a nonsense one, which is worse than either
         * the markup or nothing.
         */
        $text = (string) preg_replace('/<(br|\/p|\/li|\/div|\/h[1-6])[^>]*>/i', ' $0', $raw);

        $text = strip_tags($text);

        // After the tags, not before: an entity can encode a bracket, and
        // decoding first would hand `strip_tags()` markup it never saw.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Feeds are full of non-breaking spaces, tabs and hard newlines. On one
        // line they are all just a space.
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));

        if (mb_strlen($text) < self::MINIMUM) {
            return null;
        }

        // Word boundary, not a hard cut: `Str::limit` breaks mid-word, and a
        // truncated word reads as corrupted data rather than as a summary.
        return Str::limit($text, $limit, '…', preserveWords: true);
    }
}
