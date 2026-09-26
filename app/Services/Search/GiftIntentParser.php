<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\EventType;
use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;
use Illuminate\Support\Facades\Lang;

/**
 * Read a gift search as intent: "cadeau voor mijn zus die van tuinieren houdt,
 * €30–€50" becomes sister · gardening · €30–€50, and what is left over stays
 * as search words.
 *
 * Roadmap step 4 (docs/strategy.md, section 5). No AI: this runs inside the
 * search request (invariant 1), in well under a millisecond, from word lists
 * per language (`lang/{nl,en,fr,es}/intent.php`). Every value it can produce
 * is a case of RecipientType, Interest or EventType, so the suggestion engine
 * understands all of it. An interest's own label is always recognised as well
 * as the synonyms in the list.
 *
 * Deliberately conservative:
 *
 * - **A gift search needs a sign that it is one**: a trigger ("cadeau voor"),
 *   a recipient ("zus") or an occasion ("verjaardag"). "Tuinhandschoenen" is
 *   a product search, and stays one.
 * - **A budget needs a word or a currency sign.** "onder 50", "€30-€50",
 *   "tussen 30 en 50 euro" are budgets; "iphone 15-16" is not.
 * - **A budget without gift words still counts**: "koptelefoon onder 100"
 *   searches for headphones with a maximum of €100.
 * - **One recipient**, the first and longest phrase found; interests as many
 *   as are named.
 * - **"Die alles al heeft"** ("who has everything") sets the brief's
 *   has-everything flag and is itself a sign of a gift search
 *   (docs/features/has-everything.md).
 *
 * See docs/features/intent-search.md.
 */
class GiftIntentParser
{
    /** Five digits of euros is a price; more is a product code. */
    private const NUMBER = '(\d{1,5})(?:[.,](\d{1,2}))?';

    private const CURRENCY = '(?:€|eur|euro|euros)';

    /**
     * @param  bool  $giftContext  the text is already about a gift, as a list's
     *                             title is: its interests count without a
     *                             "cadeau voor" in front (CountListSignals)
     */
    public function parse(string $text, Market $market, bool $giftContext = false): ParsedIntent
    {
        $original = trim($text);
        $words = Lang::get('intent', [], $market->language());

        if ($original === '' || ! is_array($words)) {
            return new ParsedIntent($original, rest: $original);
        }

        $s = ' '.mb_strtolower(str_replace(['–', '—', '−'], '-', $original)).' ';
        $phrases = [];

        [$budgetMin, $budgetMax, $budgetPhrase] = $this->budget($s, $words);

        if ($budgetPhrase !== null) {
            $phrases['budget'] = $budgetPhrase;
            $s = $this->remove($s, $budgetPhrase);
        }

        $trigger = false;

        foreach ($this->longestFirst(array_fill_keys($words['triggers'] ?? [], true)) as $phrase => $unused) {
            if ($this->contains($s, $phrase)) {
                $trigger = true;
                $s = $this->remove($s, $phrase);
            }
        }

        /*
         * "Die alles al heeft": read before the recipient, so "mijn man die
         * alles al heeft" still finds the man, and taken out of the text so
         * "alles" is not searched for. A sign of a gift search on its own.
         */
        $hasEverything = false;

        foreach ($this->longestFirst(array_fill_keys($words['has_everything'] ?? [], true)) as $phrase => $unused) {
            if ($this->contains($s, (string) $phrase)) {
                $hasEverything = true;
                $phrases['has_everything'] ??= (string) $phrase;
                $s = $this->remove($s, (string) $phrase);
            }
        }

        [$relationship, $recipientPhrase] = $this->first($s, $this->vocabulary($words['recipients'] ?? [], RecipientType::values()));

        if ($recipientPhrase !== null) {
            $phrases['recipient'] = $recipientPhrase;
            $s = $this->remove($s, $recipientPhrase);
        }

        [$occasion, $occasionPhrase] = $this->first($s, $this->vocabulary($words['occasions'] ?? [], array_values(array_diff(EventType::values(), [EventType::Other->value])), fn (string $v) => EventType::from($v)->label($market->language())));

        if ($occasionPhrase !== null) {
            $phrases['occasion'] = $occasionPhrase;
            $s = $this->remove($s, $occasionPhrase);
        }

        $interests = [];

        foreach ($this->longestFirst($this->vocabulary($words['interests'] ?? [], array_map(fn (Interest $i) => $i->value, Interest::cases()), fn (string $v) => (string) trans('site.gift.interests.'.$v, [], $market->language()))) as $phrase => $interest) {
            if ($this->contains($s, $phrase)) {
                if (! in_array($interest, $interests, true)) {
                    $interests[] = $interest;
                    $phrases['interest:'.$interest] = $phrase;
                }
                $s = $this->remove($s, $phrase);
            }
        }

        $isGift = $giftContext || $trigger || $hasEverything || $relationship !== null || $occasion !== null;

        return new ParsedIntent(
            original: $original,
            isGift: $isGift,
            relationship: $relationship,
            // Interests without a gift around them are just search words: the
            // leftover keeps them, below.
            interests: $isGift ? $interests : [],
            occasion: $occasion,
            budgetMin: $budgetMin,
            budgetMax: $budgetMax,
            rest: $isGift ? $this->rest($s, $words['filler'] ?? []) : $this->rest($this->restore($original, $budgetPhrase), []),
            phrases: $isGift ? $phrases : array_intersect_key($phrases, ['budget' => true]),
            hasEverything: $hasEverything,
        );
    }

    /**
     * Whether a phrase says "someone who has everything", for text that is
     * not a search: a free-text interest in the Gift Finder ("heeft alles
     * al"), which the wizard would otherwise search for word for word.
     */
    public function saysHasEverything(string $text, Market $market): bool
    {
        return $this->parse($text, $market, giftContext: true)->hasEverything;
    }

    /**
     * "€30-€50", "tussen 30 en 50", "onder de 50", "50 euro".
     *
     * @param  array<string, mixed>  $words
     * @return array{0: int|null, 1: int|null, 2: string|null}
     */
    private function budget(string $s, array $words): array
    {
        $n = self::NUMBER;
        $c = self::CURRENCY;
        $between = $this->alternation($words['between'] ?? []);
        $and = $this->alternation(['-', ...($words['and'] ?? [])]);
        $under = $this->alternation($words['under'] ?? []);

        // A range: needs "tussen" in front or a currency somewhere in it.
        if (preg_match("/(?<![\\p{L}\\p{N}])(?:({$between})\\s+)?({$c}\\s*)?{$n}\\s*({$c})?\\s*(?:{$and})\\s*({$c}\\s*)?{$n}\\s*({$c})?(?![\\p{L}\\p{N}])/u", $s, $m)) {
            $hasSign = ($m[2] ?? '') !== '' || ($m[5] ?? '') !== '' || ($m[6] ?? '') !== '' || ($m[9] ?? '') !== '';

            if (($m[1] ?? '') !== '' || $hasSign) {
                $low = $this->cents($m[3], $m[4] ?? '');
                $high = $this->cents($m[7], $m[8] ?? '');

                if ($low < $high) {
                    return [$low, $high, trim($m[0])];
                }
            }
        }

        if ($under !== '' && preg_match("/(?<![\\p{L}\\p{N}])(?:{$under})\\s*{$c}?\\s*{$n}\\s*{$c}?(?![\\p{L}\\p{N}])/u", $s, $m)) {
            return [null, $this->cents($m[1], $m[2] ?? ''), trim($m[0])];
        }

        // One amount with a currency sign: a ceiling.
        if (preg_match("/(?<![\\p{L}\\p{N}])(?:{$c}\\s*{$n}|{$n}\\s*{$c})(?![\\p{L}\\p{N}])/u", $s, $m)) {
            $whole = ($m[1] ?? '') !== '' ? $m[1] : $m[3];
            $fraction = ($m[1] ?? '') !== '' ? ($m[2] ?? '') : ($m[4] ?? '');

            return [null, $this->cents($whole, $fraction), trim($m[0])];
        }

        return [null, null, null];
    }

    private function cents(string $whole, string $fraction): int
    {
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /**
     * Synonyms plus each value's own label, as phrase => value.
     *
     * @param  array<string, list<string>>  $lists
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    private function vocabulary(array $lists, array $allowed, ?callable $label = null): array
    {
        $out = [];

        foreach ($allowed as $value) {
            if ($label !== null) {
                $own = mb_strtolower(trim($label($value)));

                if ($own !== '' && ! str_contains($own, '.')) {
                    $out[$own] = $value;
                }
            }

            foreach ($lists[$value] ?? [] as $phrase) {
                $out[mb_strtolower($phrase)] = $value;
            }
        }

        return $out;
    }

    /**
     * The value of the longest phrase found, and the phrase.
     *
     * @param  array<string, string>  $vocabulary
     * @return array{0: string|null, 1: string|null}
     */
    private function first(string $s, array $vocabulary): array
    {
        foreach ($this->longestFirst($vocabulary) as $phrase => $value) {
            if ($this->contains($s, $phrase)) {
                return [$value, $phrase];
            }
        }

        return [null, null];
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $phrases
     * @return array<string, T>
     */
    private function longestFirst(array $phrases): array
    {
        uksort($phrases, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $phrases;
    }

    private function contains(string $s, string $phrase): bool
    {
        return (bool) preg_match($this->pattern($phrase), $s);
    }

    private function remove(string $s, string $phrase): string
    {
        return preg_replace($this->pattern($phrase), ' ', $s) ?? $s;
    }

    private function pattern(string $phrase): string
    {
        return '/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower($phrase), '/').'(?![\p{L}\p{N}])/u';
    }

    /** @param  list<string>  $words */
    private function alternation(array $words): string
    {
        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return implode('|', array_map(fn (string $w) => preg_quote(mb_strtolower($w), '/'), $words));
    }

    /**
     * What is left once the intent is taken out, filler words dropped.
     *
     * @param  list<string>  $filler
     */
    private function rest(string $s, array $filler): string
    {
        foreach ($this->longestFirst(array_fill_keys($filler, true)) as $word => $unused) {
            $s = $this->remove($s, $word);
        }

        $s = preg_replace('/[,.;:!?()"]+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** The original text with the budget words taken out, case kept. */
    private function restore(string $original, ?string $budget): string
    {
        if ($budget === null) {
            return $original;
        }

        $normalised = str_replace(['–', '—', '−'], '-', $original);

        return preg_replace('/'.preg_quote($budget, '/').'/iu', ' ', $normalised, 1) ?? $normalised;
    }
}
