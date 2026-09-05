<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Models\ProductGroup;
use Illuminate\Support\Str;

/**
 * The two strings a product page shows a person: its heading, and its listing.
 *
 * ## Why this exists
 *
 * `product_groups.title` is not written by us. `ProductGrouper::recomputeAggregates()`
 * copies it from whichever offer is cheapest and in stock, so the most important
 * string on 302,133 pages is chosen by whichever merchant undercut the others
 * this morning — and it arrives carrying that merchant's feed conventions.
 * Measured on production, 2026-09-05:
 *
 * | Defect                              | rows    |
 * |-------------------------------------|---------|
 * | Title is ALL CAPS                   |  18,593 |
 * | Brand known, but absent from title  |  38,495 |
 * | Median length, `en` market          | 121 ch  |
 *
 * A search listing shows about 60 characters, so in the English market 85.4% of
 * product titles were cut off; 18,593 shouted, which Google answers by rewriting
 * the title itself rather than showing it; and 38,495 omitted the single
 * highest-intent word a product query contains, while holding it in the column
 * next door.
 *
 * ## Presentation, not storage
 *
 * Nothing here writes to the database. The stored title stays exactly as the
 * feed sent it, because it is also the input to search indexing, to
 * {@see ProductDescription} matching and to the slug — all of which want the
 * merchant's own words. `ProductGrouper` fixes what it can at the source by
 * preferring a well-formed offer title in the first place; this fixes what
 * survives that, at the moment of rendering.
 */
final class ProductTitle
{
    /**
     * The whole listing title's budget, shop count included.
     *
     * A search result shows about 60 characters and `app.tsx` appends
     * " · GiftCoves", so 48 are ours. The suffix is measured rather than
     * guessed at: " — at 5 shops" and " — chez 12 boutiques" differ by eight
     * characters, and a constant that fits one language truncates another.
     */
    private const LISTING_MAX = 48;

    /**
     * A title cut below this is not a product name any more.
     *
     * Only reachable in a language whose suffix is unusually long against a
     * brand whose name is unusually long. Better a listing two characters over
     * than one reading "JBL Charge —".
     */
    private const HEADING_FLOOR = 20;

    /**
     * Tokens that are not words and must not be title-cased.
     *
     * Anything containing a digit is caught by rule rather than listed — model
     * numbers and capacities are the large majority. What remains is the set of
     * pure-letter acronyms common enough in a consumer catalogue that lowercasing
     * them reads as a typo: "Usb" and "Oled" look like mistakes in a way that
     * "Bluetooth" does not.
     */
    private const ACRONYMS = [
        'USB', 'USB-C', 'LED', 'LCD', 'OLED', 'QLED', 'HDMI', 'RGB', 'GPS', 'NFC',
        'ANC', 'TWS', 'HD', 'UHD', 'TV', 'PC', 'DVD', 'CD', 'SSD', 'HDD', 'RAM',
        'CPU', 'GPU', 'WIFI', 'WI-FI', 'BBQ', 'UV', 'SPF', 'XL', 'XXL', 'XS',
        'RVS', 'ABS', 'PVC', 'EU', 'UK', 'US', 'AC', 'DC', 'IP', 'AI',
    ];

    /**
     * The heading: the merchant's title, de-shouted and carrying its brand.
     *
     * Untrimmed on purpose. An `<h1>` sits above the page it names and has the
     * width of the page to do it in; only the listing pays for length.
     */
    public static function heading(ProductGroup $group): string
    {
        return self::withBrand(self::deShout(trim($group->title), $group->brand), $group->brand);
    }

    /**
     * The listing: the heading, cut to fit, with the shop count where it earns
     * its place.
     *
     * The count is the differentiator this site has and its competitors do not,
     * and it is the safe half of the pair — see the note beside
     * `product.seo_title_multi` for why the price stays in the description.
     */
    public static function listing(ProductGroup $group): string
    {
        $heading = self::heading($group);

        if ($group->merchant_count <= 1) {
            return self::truncate($heading, self::LISTING_MAX);
        }

        /*
         * Rendered once with an empty title to measure what the template and
         * this particular count actually cost, then again with the title cut to
         * what is left. Measuring beats a constant for the same reason the
         * search and brand guards measure: the suffix is a different length in
         * each of the four languages, and "at 5 shops" is shorter than "chez
         * 12 boutiques".
         */
        $fixed = mb_strlen(__('site.product.seo_title_multi', [
            'title' => '',
            'count' => $group->merchant_count,
        ]));

        return __('site.product.seo_title_multi', [
            'title' => self::truncate($heading, max(self::HEADING_FLOOR, self::LISTING_MAX - $fixed)),
            'count' => $group->merchant_count,
        ]);
    }

    /**
     * Title-case a title that is shouting, and leave every other title alone.
     *
     * The test is deliberately narrow: uppercase-only *and* carrying a run of
     * four or more letters. "JBL" and "LG" are brands that are genuinely
     * capitalised, and a two-word title of two short acronyms is far more likely
     * to be correct than to be a feed shouting.
     */
    private static function deShout(string $title, ?string $brand = null): string
    {
        if ($title === '' || mb_strtoupper($title) !== $title) {
            return $title;
        }

        if (! preg_match('/\p{Lu}{4}/u', $title)) {
            return $title;
        }

        /*
         * The brand's own tokens are exempt.
         *
         * "JBL TUNER 3" is a shouting title containing a brand that is
         * genuinely three capitals, and title-casing the lot returns "Jbl",
         * which is a misspelling of somebody's trademark on 8,950 pages in one
         * market alone. The brand row is the authority on how the brand is
         * written, so its own casing is carried through verbatim.
         */
        $keep = [];

        foreach (preg_split('/\s+/u', (string) $brand, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $keep[mb_strtoupper($token)] = $token;
        }

        $words = preg_split('/(\s+)/u', $title, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        return implode('', array_map(fn (string $w): string => self::recase($w, $keep), $words));
    }

    /**
     * One whitespace-delimited token of a shouting title.
     *
     * @param  array<string, string>  $keep  brand tokens, by their upper-case form
     */
    private static function recase(string $word, array $keep = []): string
    {
        if (trim($word) === '') {
            return $word;
        }

        // Model numbers, capacities, voltages: "512GB", "WH-1000XM5", "18V".
        if (preg_match('/\d/u', $word)) {
            return $word;
        }

        $bare = trim($word, '(),.:;·|-');

        if (isset($keep[mb_strtoupper($bare)])) {
            return str_replace($bare, $keep[mb_strtoupper($bare)], $word);
        }

        if (in_array($bare, self::ACRONYMS, true)) {
            return $word;
        }

        return mb_convert_case(mb_strtolower($word, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Put the brand in front when the title does not already carry it.
     *
     * Compared as slugs, never as substrings, because the feeds disagree about
     * punctuation — "Audio-Technica" and "Audio Technica" are one brand, and a
     * `str_contains` on the raw strings says otherwise. This is the same folding
     * `brand_stats` uses for exactly the same reason, and it is done in PHP for
     * the same one: Postgres cannot reproduce `Str::slug()`, which transliterates
     * where `lower(replace(...))` does not.
     */
    private static function withBrand(string $title, ?string $brand): string
    {
        $brand = $brand === null ? '' : trim($brand);

        if ($brand === '' || $title === '') {
            return $title;
        }

        $needle = Str::slug($brand);

        if ($needle === '' || str_contains(Str::slug($title), $needle)) {
            return $title;
        }

        return $brand.' '.$title;
    }

    /**
     * Cut at a separator rather than mid-phrase.
     *
     * A title cut mid-word looks broken in a listing, and one cut mid-phrase
     * ("JBL Charge 6 Draadloze Bluetooth Speaker met") reads as a sentence that
     * failed. Preferring a comma, dash or pipe means the cut usually lands where
     * the merchant had already ended a clause. No ellipsis: the count that
     * follows is the visible signal that the title is not the whole string, and
     * an ellipsis plus a dash reads as two interruptions.
     */
    private static function truncate(string $title, int $max): string
    {
        if (mb_strlen($title) <= $max) {
            return $title;
        }

        $cut = mb_substr($title, 0, $max);
        $best = 0;

        foreach ([',', ' - ', ' – ', ' — ', ' | ', ' '] as $separator) {
            $at = mb_strrpos($cut, $separator);

            // Past halfway only. A separator near the start would leave two
            // words standing in for a product name.
            if ($at !== false && $at > $max / 2) {
                $best = max($best, $at);
            }
        }

        return rtrim(mb_substr($cut, 0, $best > 0 ? $best : $max), " \t\n\r\0\x0B,.;:-–—|");
    }
}
