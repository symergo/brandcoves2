<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use App\Enums\Vibe;
use App\Models\Recipient;

/**
 * What we know about a person's taste.
 *
 * Named for whose taste it describes rather than what it is used for, because
 * it turned out to describe both sides of the same act: the brief you write
 * about your mother and the brief you write about yourself are the same object
 * over the same catalogue. Only the {@see SuggestionProfile} differs.
 *
 * A value object rather than an array, so that adding a question is a
 * compiler-visible change rather than a string key someone forgets to read on
 * the other side.
 */
final readonly class TasteBrief
{
    /**
     * @param  list<string>  $interests  Interest enum values and/or free text
     * @param  list<string>  $avoid  hard exclusions, matched against the title
     * @param  list<string>  $values  'sustainable', 'local', 'handmade'
     * @param  list<int>  $excludeGroupIds  already shown, swapped away, or on the list
     * @param  string|null  $query  a typed search, when the person also knows what they want
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
        public ?SuggestionProfile $profile = null,
        public ?string $query = null,
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

    /** How to rank. Buying for someone else is the default; it is the older path. */
    public function profile(): SuggestionProfile
    {
        return $this->profile ?? SuggestionProfile::forSomeone();
    }

    public function rankedAs(SuggestionProfile $profile): self
    {
        return $this->with(profile: $profile);
    }

    /** @param list<int> $ids */
    public function excluding(array $ids): self
    {
        return $this->with(excludeGroupIds: array_values(array_unique([...$this->excludeGroupIds, ...$ids])));
    }

    /**
     * The same brief, narrowed by something the person typed.
     *
     * This is what lets a brief *drive* a search rather than sitting beside
     * one: the budget and — more importantly — the `avoid` list bind to the
     * query, so someone searching while shopping for a person gets the same
     * protection the wizard gives them. Without it, "no alcohol" holds on the
     * suggestions page and silently stops holding the moment they use the
     * search box.
     */
    public function searching(?string $query): self
    {
        $query = $query === null ? null : trim($query);

        return $this->with(query: $query === '' ? null : $query);
    }

    public function withLimit(int $limit): self
    {
        return $this->with(limit: $limit);
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
        return $this->budgetMax ?? (int) config('giftcoves.gift.max_price');
    }

    public function floor(): int
    {
        return $this->budgetMin ?? (int) config('giftcoves.gift.min_price');
    }

    /**
     * Copy with overrides.
     *
     * Named arguments only, so adding a field to the constructor cannot leave a
     * silently-dropped property behind in a hand-written clone — which is
     * exactly what happened every time this was written out longhand.
     *
     * @param  list<string>|null  $interests
     * @param  list<string>|null  $avoid
     * @param  list<string>|null  $values
     * @param  list<int>|null  $excludeGroupIds
     */
    private function with(
        ?array $interests = null,
        ?Vibe $vibe = null,
        ?array $avoid = null,
        ?array $values = null,
        ?array $excludeGroupIds = null,
        ?int $limit = null,
        ?SuggestionProfile $profile = null,
        ?string $query = null,
    ): self {
        return new self(
            market: $this->market,
            interests: $interests ?? $this->interests,
            vibe: $vibe ?? $this->vibe,
            budgetMin: $this->budgetMin,
            budgetMax: $this->budgetMax,
            avoid: $avoid ?? $this->avoid,
            values: $values ?? $this->values,
            relationship: $this->relationship,
            occasion: $this->occasion,
            ageBand: $this->ageBand,
            excludeGroupIds: $excludeGroupIds ?? $this->excludeGroupIds,
            limit: $limit ?? $this->limit,
            profile: $profile ?? $this->profile,
            query: $query ?? $this->query,
        );
    }
}
