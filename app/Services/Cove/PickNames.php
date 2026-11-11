<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Models\DailyPick;
use App\Models\DailyPickSet;
use Illuminate\Support\Str;

/**
 * The short name a Cove's own text gives each of its products, kept on the
 * pick and shown on its card instead of the shop's title (owner, 2026-11-10:
 * "add a short product name as generated in the text to the product and show
 * it in the card").
 *
 * A shop title is written for a search engine ("NINJA AF200EU Foodi Dual Zone
 * AIRFRYER 7 6 L"); the article already calls the same thing "Ninja Foodi Dual
 * Zone". That name is the label of the product's link token, so it costs no
 * extra writing and always matches the paragraph above the card.
 *
 * - **Where it is read**: `[[product:ID|label]]` in the body, the editorial,
 *   the blurb and each pick's own copy, in reading order; the first usable
 *   label per product wins.
 * - **Usable** means 3 to 80 characters and sharing a real word with the
 *   product's title or brand. A writer may label a link "deze" or "the odd
 *   one"; that is fine in a sentence and useless on a card, and the shared word
 *   is what tells the two apart.
 * - **Filled at every build** ({@see EditionBuilder}), so a rewritten article
 *   updates its cards; a pick whose text no longer names it falls back to the
 *   shop title (`short_name` null).
 */
final class PickNames
{
    private const TOKEN = '/\[\[product:\s*(\d+)\s*\|([^\]|]{1,200})\]\]/u';

    /** Words too common to prove that a label and a title name the same thing. */
    private const STOPWORDS = [
        'van', 'voor', 'met', 'een', 'het', 'die', 'dat', 'and', 'the', 'for', 'with', 'des', 'les', 'une',
        'pour', 'avec', 'sur', 'dans', 'con', 'para', 'los', 'las', 'del', 'por', 'und', 'mit', 'der',
    ];

    /** Fill `short_name` on every pick of this Cove. Returns how many got a name. */
    public function fill(DailyPickSet $cove): int
    {
        $cove->loadMissing('picks.group');
        $labels = $this->labels($cove);
        $named = 0;

        foreach ($cove->picks as $pick) {
            $name = $this->usable($labels[$pick->group_id] ?? [], $pick);

            if ($pick->short_name !== $name) {
                // Without touching updated_at: the name is derived from the
                // text, and the text's own edit already moved the timestamp.
                DailyPick::query()->whereKey($pick->id)->toBase()->update(['short_name' => $name]);
                $pick->setAttribute('short_name', $name)->syncOriginalAttribute('short_name');
            }

            $named += $name !== null ? 1 : 0;
        }

        return $named;
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
        if ($pick->group === null) {
            return null;
        }

        $reference = self::words($pick->group->title.' '.$pick->group->displayTitle().' '.$pick->group->brand);

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
