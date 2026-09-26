<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Models\ProductGroup;

/**
 * One product as taste discovery sees it: an id, what it is tagged with, and
 * what it costs.
 *
 * Built from the database on every request and never from what the browser
 * sends, so a choice can only ever mean what the catalogue says the product
 * is. See docs/features/taste-discovery.md.
 */
final readonly class TasteCard
{
    /**
     * @param  list<string>  $tags  the editors' gift tags
     * @param  list<string>  $crowdTags  tags people's lists taught the product
     * @param  int|null  $price  cents
     * @param  list<string>  $guessed  interests read from the title and category (InterestGuesser)
     */
    public function __construct(
        public int $id,
        public array $tags = [],
        public array $crowdTags = [],
        public ?int $price = null,
        public array $guessed = [],
    ) {}

    /**
     * How much an interest read from the product's own words counts.
     *
     * The crowd's weight (0.75), below an editor's (1.0). "koffie" in a title
     * is usually right about what the thing is, but it is a word, not a
     * decision. At 0.75, two good rounds reach the profiler's bar of 1.5,
     * which is the "two good rounds" the profiler was designed around; at 0.5
     * it took three, and twelve rounds spread over forty interests rarely give
     * one interest three, so the tool went back to learning nothing but a
     * price (tried 2026-09-26).
     */
    public const GUESS_WEIGHT = 0.75;

    public static function fromGroup(ProductGroup $group): self
    {
        return new self(
            id: (int) $group->id,
            tags: $group->giftTags(),
            crowdTags: $group->crowdTags(),
            price: $group->min_price === null ? null : (int) $group->min_price,
            guessed: app(InterestGuesser::class)->interests((string) $group->title, $group->category),
        );
    }

    /**
     * The values in one vocabulary, each with how much we trust it.
     *
     * An editor's tag is a decision and counts in full; a crowd tag is many
     * people agreeing and counts at the weight the suggestion engine gives it
     * (`giftcoves.list_signals.weight`, 0.75), so the two tools weigh the
     * same evidence the same way. A value carried both ways counts once, at
     * the editor's weight.
     *
     * @return array<string, float>
     */
    public function values(string $vocabulary, float $crowdWeight = 0.75): array
    {
        $prefix = $vocabulary.':';
        $values = [];

        // Weakest first, so a tag on the same interest overwrites the guess.
        if ($vocabulary === GiftTags::INTEREST) {
            foreach ($this->guessed as $interest) {
                $values[$interest] = self::GUESS_WEIGHT;
            }
        }

        foreach ($this->crowdTags as $tag) {
            if (str_starts_with($tag, $prefix)) {
                $values[substr($tag, strlen($prefix))] = $crowdWeight;
            }
        }

        foreach ($this->tags as $tag) {
            if (str_starts_with($tag, $prefix)) {
                $values[substr($tag, strlen($prefix))] = 1.0;
            }
        }

        return $values;
    }

    /** @return list<string> */
    public function interests(): array
    {
        return array_keys($this->values(GiftTags::INTEREST));
    }

    /** Whether the two share an interest, which would make a pair teach nothing. */
    public function sharesAnInterestWith(self $other): bool
    {
        return array_intersect($this->interests(), $other->interests()) !== [];
    }
}
