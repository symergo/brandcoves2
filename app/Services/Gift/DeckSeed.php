<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * What the choosing games (This or that, Swipe gifts) start from, when
 * something is already known about who it is for (owner, 2026-09-29: start
 * from what is known, keep a share of exploring). Built by DeckSeeds.
 *
 * Two strengths, on purpose:
 *
 * - `known`: interests somebody actually has, from a saved person, their own
 *   "Mijn smaak" or yours. Swipe gifts treats them as liked from the first
 *   card; This or that opens its first rounds on them.
 * - `explore`: interests typical of a relationship ("mother": gardening,
 *   wellness...). A stereotype, not a fact about this mother, so they are
 *   only shown first while exploring, and only a like makes one stick.
 *
 * And three rules that leave a card out: an interest to avoid (what was said,
 * plus a relationship's exclusions such as drinks for a child), an age tag for
 * another age, and a `recipient:` tag for somebody else. A card without such
 * tags is never left out for lacking them: most products carry none.
 */
final class DeckSeed
{
    /**
     * @param  list<string>  $known  interest values
     * @param  list<string>  $explore  interest values, shown first while exploring
     * @param  list<string>  $avoid  interest values
     */
    public function __construct(
        public readonly array $known = [],
        public readonly array $explore = [],
        public readonly array $avoid = [],
        public readonly ?string $ageBand = null,
        public readonly ?string $recipient = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->known === [] && $this->explore === [] && $this->avoid === []
            && $this->ageBand === null && $this->recipient === null;
    }

    /**
     * The interests to open on: the known ones first, then the typical ones,
     * never one to avoid.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        return array_values(array_diff(array_unique([...$this->known, ...$this->explore]), $this->avoid));
    }

    public function allows(TasteCard $card): bool
    {
        if ($this->avoid !== [] && array_intersect($card->interests(), $this->avoid) !== []) {
            return false;
        }

        $tags = [...$card->tags, ...$card->crowdTags];

        if ($this->ageBand !== null && ! $this->fits($tags, GiftTags::AGE, $this->ageBand)) {
            return false;
        }

        return $this->recipient === null || $this->fits($tags, GiftTags::RECIPIENT, $this->recipient);
    }

    /**
     * @param  list<TasteCard>  $pool
     * @return list<TasteCard>
     */
    public function filter(array $pool): array
    {
        return $this->isEmpty() ? $pool : array_values(array_filter($pool, $this->allows(...)));
    }

    /**
     * No tag in this vocabulary says nothing; a tag says who it is for.
     *
     * @param  list<string>  $tags
     */
    private function fits(array $tags, string $vocabulary, string $value): bool
    {
        $prefix = $vocabulary.':';
        $values = array_map(fn (string $t) => substr($t, strlen($prefix)), array_filter($tags, fn (string $t) => str_starts_with($t, $prefix)));

        return $values === [] || in_array($value, $values, true);
    }
}
