<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use App\Enums\Vibe;
use App\Models\Recipient;

/**
 * Everything the wizard learned about the person being bought for.
 *
 * A value object rather than an array, so that adding a question to the wizard
 * is a compiler-visible change rather than a string key someone forgets to read
 * on the other side.
 */
final readonly class GiftBrief
{
    /**
     * @param  list<string>  $interests  Interest enum values and/or free text
     * @param  list<string>  $avoid  hard exclusions, matched against the title
     * @param  list<string>  $values  'sustainable', 'local', 'handmade'
     * @param  list<int>  $excludeGroupIds  already shown, swapped away, or on the list
     */
    public function __construct(
        public Market $market,
        public array $interests = [],
        public ?Vibe $vibe = null,
        public ?int $budgetMin = null,
        public ?int $budgetMax = null,
        public array $avoid = [],
        public array $values = [],
        public ?string $relationship = null,
        public ?string $occasion = null,
        public ?string $ageBand = null,
        public array $excludeGroupIds = [],
        public int $limit = 4,
    ) {}

    public static function fromRecipient(Recipient $recipient, Market $market, int $limit = 4): self
    {
        return new self(
            market: $market,
            interests: array_values(array_filter((array) $recipient->interests)),
            vibe: $recipient->vibe === null ? null : Vibe::tryFrom($recipient->vibe),
            budgetMin: $recipient->budget_min,
            budgetMax: $recipient->budget_max,
            avoid: array_values(array_filter((array) $recipient->avoid)),
            values: array_values(array_filter((array) $recipient->values)),
            relationship: $recipient->relationship,
            occasion: $recipient->occasion,
            ageBand: $recipient->age_band,
            limit: $limit,
        );
    }

    /** @param list<int> $ids */
    public function excluding(array $ids): self
    {
        return new self(
            market: $this->market,
            interests: $this->interests,
            vibe: $this->vibe,
            budgetMin: $this->budgetMin,
            budgetMax: $this->budgetMax,
            avoid: $this->avoid,
            values: $this->values,
            relationship: $this->relationship,
            occasion: $this->occasion,
            ageBand: $this->ageBand,
            excludeGroupIds: array_values(array_unique([...$this->excludeGroupIds, ...$ids])),
            limit: $this->limit,
        );
    }

    /**
     * The budget ceiling actually used for retrieval.
     *
     * Falls back to the giftability band, so a brief with no stated budget still
     * excludes the €2,500 television. Someone who declines to name a number has
     * not said "anything".
     */
    public function ceiling(): int
    {
        return $this->budgetMax ?? (int) config('brandcoves.gift.max_price');
    }

    public function floor(): int
    {
        return $this->budgetMin ?? (int) config('brandcoves.gift.min_price');
    }
}
