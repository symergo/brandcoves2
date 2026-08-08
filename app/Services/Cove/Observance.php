<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\Market;

/**
 * A day worth building an edition around.
 *
 * "International Pet Day" is a better reason to open a shopping page than
 * "Tuesday". The theme comes from a translation key rather than the config, so
 * one calendar serves five markets.
 *
 * Two kinds, distinguished by `$evergreen`:
 *
 *  - A **named day** is a factual claim about the date. Get it wrong and you
 *    are wrong in public, once a year, forever. These are few and checked.
 *  - An **evergreen theme** claims nothing about the date at all; "the desk
 *    reset" is true on any Tuesday. These fill the rest of the year.
 *
 * The distinction is not cosmetic — the copy differs. A named day may say
 * "It's World Pizza Day"; an evergreen theme must never imply a date has a
 * name, because it does not.
 */
final readonly class Observance
{
    /** @param list<string> $queries */
    public function __construct(
        public string $key,
        public array $queries = [],
        public bool $evergreen = false,
    ) {}

    /**
     * The day's name, in the market's language.
     *
     * Falls back through English before giving up, and gives up by returning
     * null rather than a dotted key — a missing translation must never reach a
     * reader as `site.daily.observances.pets.title`, and it is the sort of thing
     * that happens the moment someone adds a date to the config and translates
     * it in four files but not five.
     *
     * A day with no copy is simply not a themed day, which the builder already
     * handles: it falls through to the model or the rotation.
     */
    public function title(Market $market): ?string
    {
        foreach ([$market->language(), 'en'] as $language) {
            $title = __($this->line('title'), [], $language);

            if (! str_contains($title, $this->namespace())) {
                return $title;
            }
        }

        return null;
    }

    /** Whether this observance has enough copy to be worth naming a day after. */
    public function isUsable(Market $market): bool
    {
        return $this->title($market) !== null;
    }

    /**
     * One line of framing under the title.
     *
     * Unlike the title this does NOT fall back to English: a Dutch page with an
     * English sentence under a Dutch heading looks broken in a way a missing
     * sentence does not. Returning null is the better failure.
     */
    public function blurb(Market $market): ?string
    {
        $blurb = __($this->line('blurb'), [], $market->language());

        // A missing translation returns the key. Better to show no blurb than a
        // dotted path to a reader.
        return str_contains($blurb, $this->namespace()) ? null : $blurb;
    }

    public function slug(): string
    {
        return ($this->evergreen ? 'theme-' : 'observance-').$this->key;
    }

    /**
     * `day_themes`, not `themes`: `site.daily.themes` is already a flat list of
     * fallback titles, and a lookup of `site.daily.themes.cosy.title` against a
     * list silently resolves to nothing.
     */
    private function namespace(): string
    {
        return $this->evergreen ? 'site.daily.day_themes' : 'site.daily.observances';
    }

    private function line(string $part): string
    {
        return $this->namespace().'.'.$this->key.'.'.$part;
    }
}
