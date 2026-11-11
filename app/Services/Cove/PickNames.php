<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use Illuminate\Support\Str;

/**
 * Gives a product without a display title the short name a Cove's text uses
 * for it (owner, 2026-11-10/11: "add a short product name as generated in the
 * text to the product and show it in the card", then "switch to display_title
 * when empty. Use the display title when it exists").
 *
 * A shop title is written for a search engine ("NINJA AF200EU Foodi Dual Zone
 * AIRFRYER 7 6 L"); the article already calls the same thing "Ninja Foodi Dual
 * Zone". That name is the label of the product's link token, so it costs no
 * extra writing, and once it is the product's `display_title` every card on
 * the site shows it through `ProductGroup::displayTitle()`.
 *
 * - **Only an empty `display_title` is filled.** A title somebody wrote, or an
 *   earlier Cove's name, is never replaced: the first Cove to name a product
 *   names it. The update itself carries `whereNull('display_title')`, so two
 *   builds racing cannot overwrite each other either.
 * - **Where it is read**: `[[product:ID|label]]` in the body, the editorial,
 *   the blurb and each pick's own copy, in reading order; the first usable
 *   label per product wins.
 * - **Usable** means 3 to 80 characters and sharing a real word with the
 *   product's title or brand. A writer may label a link "deze" or "the odd
 *   one"; that is fine in a sentence and no name for a product, and the shared
 *   word is what tells the two apart.
 * - **Run at every build** ({@see EditionBuilder}) and once over the existing
 *   Coves by the migration that introduced it.
 */
final class PickNames
{
    private const TOKEN = '/\[\[product:\s*(\d+)\s*\|([^\]|]{1,200})\]\]/u';

    /** Words too common to prove that a label and a title name the same thing. */
    private const STOPWORDS = [
        'van', 'voor', 'met', 'een', 'het', 'die', 'dat', 'and', 'the', 'for', 'with', 'des', 'les', 'une',
        'pour', 'avec', 'sur', 'dans', 'con', 'para', 'los', 'las', 'del', 'por', 'und', 'mit', 'der',
    ];

    /** Fill the empty display titles of this Cove's products. Returns how many were written. */
    public function fill(DailyPickSet $cove): int
    {
        $cove->loadMissing('picks.group');
        $labels = $this->labels($cove);
        $written = 0;

        foreach ($cove->picks as $pick) {
            if ($pick->group === null || filled($pick->group->display_title)) {
                continue;
            }

            $name = $this->usable($labels[$pick->group_id] ?? [], $pick);

            if ($name === null) {
                continue;
            }

            // Without touching updated_at: the sitemap reads it as the
            // product page's last change, and the page's facts did not change.
            $written += ProductGroup::query()
                ->whereKey($pick->group_id)
                ->whereNull('display_title')
                ->toBase()
                ->update(['display_title' => $name]);

            $pick->group->setAttribute('display_title', $name)->syncOriginalAttribute('display_title');
        }

        return $written;
    }

    /**
     * Every label per product id, in reading order.
     *
     * @return array<int, list<string>>
     */
    public function labels(DailyPickSet $cove): array
    {
        $texts = [$cove->body, $cove->editorial, $cove->theme_blurb];

        foreach ($cove->picks as $pick) {
            $texts[] = $pick->blurb;
        }

        $labels = [];

        foreach ($texts as $text) {
            if (! is_string($text) || $text === '') {
                continue;
            }

            preg_match_all(self::TOKEN, $text, $matches, PREG_SET_ORDER);

            foreach ($matches as $m) {
                $labels[(int) $m[1]][] = html_entity_decode(trim($m[2]), ENT_QUOTES);
            }
        }

        return $labels;
    }

    /** @param  list<string>  $labels */
    private function usable(array $labels, DailyPick $pick): ?string
    {
        $reference = self::words($pick->group->title.' '.$pick->group->brand);

        foreach ($labels as $label) {
            $label = trim((string) preg_replace('/\s+/u', ' ', strip_tags($label)));
            $length = mb_strlen($label);

            if ($length < 3 || $length > 80) {
                continue;
            }

            if (array_intersect(self::words($label), $reference) !== []) {
                return Str::ucfirst($label);
            }
        }

        return null;
    }

    /** @return list<string> lower-case, accent-free words of three or more characters */
    private static function words(?string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii((string) $text))) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true),
        )));
    }
}
