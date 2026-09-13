<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a gift should look like.
 *
 * The second half of taste, and the half {@see Vibe} cannot carry. Vibe is
 * what a present is *for* — useful, fun, beautiful — and it is deliberately
 * three, because it is the question that most changes the answer. Style is
 * what it *looks like*, and the two are independent: a useful present can be
 * modern or vintage, and a beautiful one can be minimal or colourful. Asking
 * them as one question forced a choice between two things nobody trades off
 * (owner's call, 2026-09-14: "practical vs design, modern vs vintage, useful
 * vs beautiful").
 *
 * Several may be chosen, unlike a vibe. A person who likes both vintage and
 * colourful things is describing one taste, not two.
 */
enum Style: string
{
    case Modern = 'modern';
    case Vintage = 'vintage';
    case Classic = 'classic';
    case Minimal = 'minimal';
    case Colourful = 'colourful';
    case Natural = 'natural';
    case Cosy = 'cosy';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    public function label(): string
    {
        return __('site.gift.styles.'.$this->value);
    }

    /**
     * Words that pull results toward this style.
     *
     * The same nudge {@see Vibe::keywords()} applies and for the same reason:
     * a feed title says "eiken" far more often than any editor will get round
     * to tagging `style:natural`, so the words stand in until a tag exists.
     * Matched as substrings, because Dutch writes compounds closed — `hout`
     * has to meet `houten` and `bamboehout`.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::Modern => ['modern', 'strak', 'contemporain', 'smart', 'led'],
            self::Vintage => ['vintage', 'retro', 'nostalg', 'jaren ', 'antiek'],
            self::Classic => ['klassiek', 'classic', 'tijdloos', 'timeless', 'traditioneel'],
            self::Minimal => ['minimal', 'sober', 'basic', 'essential', 'mat zwart'],
            self::Colourful => ['kleurrijk', 'colour', 'color', 'regenboog', 'rainbow', 'multicolor'],
            self::Natural => ['hout', 'wood', 'bamboe', 'bamboo', 'linnen', 'linen', 'katoen', 'natuur'],
            self::Cosy => ['knus', 'cosy', 'cozy', 'fleece', 'plaid', 'zacht', 'warm'],
        };
    }
}
