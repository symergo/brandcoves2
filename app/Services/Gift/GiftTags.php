<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\EventType;
use App\Enums\Interest;
use App\Enums\Preference;
use App\Enums\RecipientType;
use App\Enums\Vibe;

/**
 * What a product can be tagged with, and nothing else.
 *
 * Seven vocabularies, each one the site already speaks: the interests the
 * gift wizard offers, the occasions a list can carry, the kinds of person a
 * gift is for, how old they are, and the wizard's three other questions, how
 * it should feel, what it should look like and what should matter about it. A
 * tag is `<vocabulary>:<value>`, so `interest:coffee`, `occasion:christmas`,
 * `recipient:mother`, `age:13-17`, `vibe:playful`, `preference:vintage`,
 * `values:handmade`. Closed rather than free text
 * because a tag is only worth having when the wizard can ask for exactly it:
 * a brief says "coffee" and a product says `interest:coffee`, and the two meet
 * without a text match in between.
 *
 * Written by editors over the editorial API (`POST /products/tags`), for the
 * same few hundred products per market that get a display title; never by a
 * job and never by a model. See docs/features/gift-tags.md.
 */
class GiftTags
{
    public const INTEREST = 'interest';

    public const OCCASION = 'occasion';

    public const RECIPIENT = 'recipient';

    public const AGE = 'age';

    public const VIBE = 'vibe';

    public const PREFERENCE = 'preference';

    public const VALUES = 'values';

    /** What the wizard offers under "anything that matters?", and `SuggestionEngine::VALUE_MARKERS` guesses from titles. */
    public const VALUE_OPTIONS = ['sustainable', 'local', 'handmade'];

    /**
     * Occasions a product can be for that a list cannot carry as its event.
     *
     * The list occasions (`EventType`) are dated things a person plans a list
     * towards. These are the rest of the calendar people buy presents for
     * (owner's call, 2026-09-14): the feasts, the milestones without a list,
     * and the things a brief types as an occasion. A tag, not an EventType,
     * because adding an EventType changes what a list can be for and what the
     * reminders send, and none of these needs a reminder.
     */
    public const EXTRA_OCCASIONS = [
        'sinterklaas', 'easter', 'new_year', 'halloween', 'communion', 'christening',
        'engagement', 'get_well', 'new_job', 'secret_santa',
    ];

    /**
     * Age bands, as ranges of years (owner's call, 2026-09-14: real age
     * groups, not words). The cuts follow how presents change: a two-year-old
     * and a four-year-old want different things, a fourteen-year-old and a
     * twenty-year-old more so, and past thirty the decades are what a giver
     * knows. An editor tags the range a product suits, and the wizard asks
     * the giver to pick one of the same ranges (owner's call: fixed groups
     * on both sides, nothing typed and nothing folded), so the two meet as
     * the same string.
     */
    public const AGE_BANDS = ['0-2', '3-5', '6-9', '10-12', '13-17', '18-29', '30-49', '50-64', '65+'];

    /**
     * The whole vocabulary, grouped, in the order the wizard asks about it.
     *
     * `other` is not an occasion a product can be for. The extra occasions
     * are (see EXTRA_OCCASIONS): the list occasions do not carry them because
     * a list has a date instead, but people shop for them.
     *
     * @return array<string, list<string>>
     */
    public static function vocabulary(): array
    {
        return [
            self::INTEREST => Interest::values(),
            self::OCCASION => [
                ...array_values(array_filter(
                    EventType::values(),
                    fn (string $v) => $v !== EventType::Other->value,
                )),
                ...self::EXTRA_OCCASIONS,
            ],
            self::RECIPIENT => RecipientType::values(),
            self::AGE => self::AGE_BANDS,
            // The wizard's "how should it feel", "which way does their taste
            // go" and "anything that matters" questions. The engine guesses
            // all three from title words ("luxe", "eiken", "duurzaam"); a tag
            // is an editor saying so, and it wins.
            self::VIBE => Vibe::values(),
            self::PREFERENCE => Preference::values(),
            self::VALUES => self::VALUE_OPTIONS,
        ];
    }

    /**
     * Every tag, fully qualified.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $all = [];

        foreach (self::vocabulary() as $prefix => $values) {
            foreach ($values as $value) {
                $all[] = $prefix.':'.$value;
            }
        }

        return $all;
    }

    /**
     * Lowercased, trimmed, deduplicated, in vocabulary order.
     *
     * Sorted so two editors tagging the same product the same way store the
     * same array, and so the stored form never depends on who typed it.
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function normalise(array $tags): array
    {
        $wanted = array_unique(array_map(fn ($t) => mb_strtolower(trim((string) $t)), $tags));

        return array_values(array_filter(self::all(), fn (string $tag) => in_array($tag, $wanted, true)));
    }

    /**
     * The tags that are not in the vocabulary, so a write can name them.
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function unknown(array $tags): array
    {
        $known = self::all();

        return array_values(array_unique(array_filter(
            array_map(fn ($t) => mb_strtolower(trim((string) $t)), $tags),
            fn (string $tag) => ! in_array($tag, $known, true),
        )));
    }

    public static function interest(string $value): string
    {
        return self::INTEREST.':'.mb_strtolower(trim($value));
    }

    public static function occasion(string $value): string
    {
        return self::OCCASION.':'.mb_strtolower(trim($value));
    }

    public static function recipient(string $value): string
    {
        return self::RECIPIENT.':'.mb_strtolower(trim($value));
    }

    public static function age(string $value): string
    {
        return self::AGE.':'.mb_strtolower(trim($value));
    }

    public static function vibe(string $value): string
    {
        return self::VIBE.':'.mb_strtolower(trim($value));
    }

    public static function preference(string $value): string
    {
        return self::PREFERENCE.':'.mb_strtolower(trim($value));
    }

    public static function value(string $value): string
    {
        return self::VALUES.':'.mb_strtolower(trim($value));
    }
}
