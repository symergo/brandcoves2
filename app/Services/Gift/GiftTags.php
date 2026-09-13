<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\EventType;
use App\Enums\Interest;
use App\Enums\RecipientType;
use App\Enums\Vibe;

/**
 * What a product can be tagged with, and nothing else.
 *
 * Six vocabularies, each one the site already speaks: the interests the
 * gift wizard offers, the occasions a list can carry, the kinds of person a
 * gift is for, how old they are, and the wizard's two other questions, how
 * it should feel and what should matter about it. A tag is
 * `<vocabulary>:<value>`, so `interest:coffee`, `occasion:christmas`,
 * `recipient:mother`, `age:teen`, `vibe:playful`, `values:handmade`. Closed
 * rather than free text
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

    public const VALUES = 'values';

    /** What the wizard offers under "anything that matters?", and `SuggestionEngine::VALUE_MARKERS` guesses from titles. */
    public const VALUE_OPTIONS = ['sustainable', 'local', 'handmade'];

    /**
     * Age bands, coarse on purpose. A present for a six-year-old and one for
     * a twelve-year-old differ, but the line between them is not one two
     * editors would draw in the same place; "child" and "teen" they would.
     * `recipients.age_band` is free text and meets these where it matches.
     */
    public const AGE_BANDS = ['baby', 'toddler', 'child', 'teen', 'adult', 'senior'];

    /**
     * The whole vocabulary, grouped, in the order the wizard asks about it.
     *
     * `other` is not an occasion a product can be for. Sinterklaas is: the
     * list occasions do not carry it because a list has a date instead, but
     * two of five markets shop for it and the Whisperer already knows the word.
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
                'sinterklaas',
            ],
            self::RECIPIENT => RecipientType::values(),
            self::AGE => self::AGE_BANDS,
            // The wizard's "how should it feel" and "anything that matters"
            // questions. The engine guesses both from title words ("luxe",
            // "duurzaam"); a tag is an editor saying so, and it wins.
            self::VIBE => Vibe::values(),
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

    public static function value(string $value): string
    {
        return self::VALUES.':'.mb_strtolower(trim($value));
    }
}
