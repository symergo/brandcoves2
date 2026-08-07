<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a gift should feel.
 *
 * Three, not seven. This is the single question that most changes the answer —
 * a practical present and a beautiful one for the same person are entirely
 * different objects — and a wizard step with seven options is a step people
 * skip.
 */
enum Vibe: string
{
    case Practical = 'practical';
    case Playful = 'playful';
    case Beautiful = 'beautiful';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $v) => $v->value, self::cases());
    }

    public function label(): string
    {
        return __('site.gift.vibes.'.$this->value);
    }

    /**
     * Words that pull results toward this vibe.
     *
     * Applied as a scoring nudge, never as a filter: someone who says "playful"
     * still wants the good headphones if headphones are the right answer.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::Practical => ['set', 'pro', 'multi', 'organizer', 'tool', 'kit', 'compact'],
            self::Playful => ['spel', 'game', 'party', 'fun', 'mini', 'retro', 'quiz', 'puzzel'],
            self::Beautiful => ['design', 'luxe', 'premium', 'handgemaakt', 'keramiek', 'linnen', 'marmer'],
        };
    }
}
