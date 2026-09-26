<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * One round of taste discovery, answered.
 *
 * Two shapes. A pair ("this or that") has a `picked` card, or none when it
 * was skipped. A single card has a verdict, like or dislike, or none when it
 * was skipped. A skip teaches nothing and is kept only so the cards in it are
 * not shown again.
 */
final readonly class TasteChoice
{
    public const LIKE = 'like';

    public const DISLIKE = 'dislike';

    /**
     * @param  list<TasteCard>  $cards  one or two
     */
    public function __construct(
        public array $cards,
        public ?int $picked = null,
        public ?string $verdict = null,
    ) {}

    public static function pair(TasteCard $left, TasteCard $right, ?int $picked): self
    {
        return new self([$left, $right], picked: $picked);
    }

    public static function single(TasteCard $card, ?string $verdict): self
    {
        return new self([$card], verdict: $verdict);
    }

    public function isPair(): bool
    {
        return count($this->cards) === 2;
    }

    public function isSkipped(): bool
    {
        return $this->isPair() ? $this->chosen() === null : ! in_array($this->verdict, [self::LIKE, self::DISLIKE], true);
    }

    /** The card that won a pair, or a single card that was liked. */
    public function chosen(): ?TasteCard
    {
        if (! $this->isPair()) {
            return $this->verdict === self::LIKE ? $this->cards[0] : null;
        }

        foreach ($this->cards as $card) {
            if ($card->id === $this->picked) {
                return $card;
            }
        }

        return null;
    }

    /** The card passed over in a pair, when one was picked. */
    public function passedOver(): ?TasteCard
    {
        $chosen = $this->chosen();

        if (! $this->isPair() || $chosen === null) {
            return null;
        }

        return $this->cards[0]->id === $chosen->id ? $this->cards[1] : $this->cards[0];
    }

    /** A single card that was disliked. */
    public function disliked(): ?TasteCard
    {
        return ! $this->isPair() && $this->verdict === self::DISLIKE ? $this->cards[0] : null;
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_map(fn (TasteCard $card) => $card->id, $this->cards);
    }
}
