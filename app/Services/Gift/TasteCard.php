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
     */
    public function __construct(
        public int $id,
        public array $tags = [],
        public array $crowdTags = [],
        public ?int $price = null,
    ) {}

    public static function fromGroup(ProductGroup $group): self
    {
        return new self(
            id: (int) $group->id,
            tags: $group->giftTags(),
            crowdTags: $group->crowdTags(),
            price: $group->min_price === null ? null : (int) $group->min_price,
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
