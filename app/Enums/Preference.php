<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Taste, as pairs of opposites.
 *
 * The wizard's older taste question is {@see Vibe}: practical, playful or
 * beautiful, pick one. It is the question that most changes the answer, and
 * it stays. What it cannot do is carry the rest of a taste, and for a day
 * (2026-09-14) the answer to that was a flat list of looks — modern, vintage,
 * cosy — which the owner corrected within the hour: "it's more than style:
 * practical vs beautiful, useful vs design, etc". The point of the examples is
 * the *vs*. A taste is not a bag of adjectives, it is a handful of choices
 * between two ways a present can go, and a person recognises their own by
 * being shown both ends.
 *
 * So seven axes, two poles each, and nothing is ever both:
 *
 * | Axis | One way | The other |
 * |---|---|---|
 * | purpose | practical | design |
 * | era | modern | vintage |
 * | tone | minimal | colourful |
 * | material | natural | technical |
 * | power | manual | powered |
 * | spend | everyday | luxurious |
 * | character | classic | quirky |
 *
 * An editor tags a product with the poles it sits at, a giver picks the ones
 * that sound like the person, and picking one pole clears the other. Neither
 * side is better: "everyday" is not a lesser "luxurious", it is a different
 * present.
 *
 * The purpose axis overlaps {@see Vibe} on purpose, and it is the axis the
 * owner asked for twice: "practical vs design". Vibe asks the same thing as
 * one pick of three (practical, playful, beautiful) and is what thousands of
 * products are already tagged with, so it stays; this is the same question in
 * the form the rest of the taste is asked in, and a product may carry both.
 * Two signals that agree are not a contradiction — they are the same fact,
 * said by an editor twice.
 */
enum Preference: string
{
    case Practical = 'practical';
    case Design = 'design';
    case Modern = 'modern';
    case Vintage = 'vintage';
    case Minimal = 'minimal';
    case Colourful = 'colourful';
    case Natural = 'natural';
    case Technical = 'technical';
    case Manual = 'manual';
    case Powered = 'powered';
    case Everyday = 'everyday';
    case Luxurious = 'luxurious';
    case Classic = 'classic';
    case Quirky = 'quirky';

    /**
     * The axes, in the order the wizard shows them.
     *
     * The order is the order a person would think of them: what the present
     * is for first, then what era, then how loud, then what it is made of,
     * then whether it plugs in, then what it costs to be, then whether it is
     * straight-faced.
     *
     * Warmth (cosy or sleek) was here for a few hours on 2026-09-14 and the
     * owner cut it: a blanket is cosy because of what it is, not because of
     * a taste somebody holds, so the axis was describing the product rather
     * than the person.
     *
     * @return list<array{axis: string, poles: array{self, self}}>
     */
    public static function axes(): array
    {
        return [
            ['axis' => 'purpose', 'poles' => [self::Practical, self::Design]],
            ['axis' => 'era', 'poles' => [self::Modern, self::Vintage]],
            ['axis' => 'tone', 'poles' => [self::Minimal, self::Colourful]],
            ['axis' => 'material', 'poles' => [self::Natural, self::Technical]],
            ['axis' => 'power', 'poles' => [self::Manual, self::Powered]],
            ['axis' => 'spend', 'poles' => [self::Everyday, self::Luxurious]],
            ['axis' => 'character', 'poles' => [self::Classic, self::Quirky]],
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }

    /** The other end of this preference's axis. Choosing one clears it. */
    public function opposite(): self
    {
        foreach (self::axes() as $axis) {
            [$left, $right] = $axis['poles'];

            if ($left === $this) {
                return $right;
            }

            if ($right === $this) {
                return $left;
            }
        }

        // Unreachable while every case sits on an axis, which axes() is the
        // single place to keep true; PreferenceTest pins it.
        return $this;
    }

    public function axis(): string
    {
        foreach (self::axes() as $axis) {
            if (in_array($this, $axis['poles'], true)) {
                return $axis['axis'];
            }
        }

        return 'other';
    }

    public function label(): string
    {
        return __('site.gift.preferences.'.$this->value);
    }

    /**
     * Words that pull results toward this pole.
     *
     * The same nudge {@see Vibe::keywords()} applies and for the same reason:
     * a feed title says "eiken" far more often than any editor will get round
     * to tagging `preference:natural`, so the words stand in until a tag
     * exists. Matched as substrings, because Dutch writes compounds closed —
     * `hout` has to meet `houten` and `bamboehout`.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::Practical => ['set', 'multi', 'organizer', 'gereedschap', 'kit', 'compact', 'handig'],
            self::Design => ['design', 'designer', 'decoratief', 'sculptuur', 'ornament', 'sierlijk'],
            self::Modern => ['modern', 'strak', 'contemporain', 'smart'],
            self::Vintage => ['vintage', 'retro', 'nostalg', 'antiek'],
            self::Minimal => ['minimal', 'sober', 'basic', 'essential'],
            self::Colourful => ['kleurrijk', 'colour', 'color', 'regenboog', 'rainbow', 'multicolor'],
            self::Natural => ['hout', 'wood', 'bamboe', 'bamboo', 'linnen', 'linen', 'katoen', 'natuur'],
            self::Technical => ['technisch', 'technical', 'digitaal', 'digital', 'precisie', 'elektronisch'],
            // "hand" alone would meet handdoek and handtas, so the words are
            // the ones that only ever mean worked by hand.
            self::Manual => ['handmatig', 'manueel', 'mechanisch', 'manual', 'hendel', 'handgedreven'],
            self::Powered => ['elektrisch', 'electric', 'accu', 'oplaadbaar', 'batterij', 'motor'],
            self::Everyday => ['dagelijks', 'alledaags', 'everyday', 'handig'],
            self::Luxurious => ['luxe', 'luxury', 'premium', 'deluxe', 'exclusief'],
            self::Classic => ['klassiek', 'classic', 'tijdloos', 'timeless', 'traditioneel'],
            self::Quirky => ['grappig', 'funny', 'quirky', 'novelty', 'gek'],
        };
    }
}
