<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use App\Enums\Preference;

/**
 * What a round of taste discovery learned about a person.
 *
 * The same questions a `TasteBrief` asks, answered by choices instead of by a
 * form, so the result drops straight into the suggestion engine and onto a
 * saved person. Made by {@see TasteProfiler}; see
 * docs/features/taste-discovery.md for how the numbers come about.
 */
final readonly class TasteProfile
{
    /** Find a gift's caps, so a saved profile never breaks its form. */
    public const MAX_INTERESTS = 8;

    public const MAX_AVOID = 10;

    public const MAX_PREFERENCES = 3;

    /**
     * @param  list<string>  $interests  strongest first
     * @param  array<string, float>  $scores  every interest seen, net score
     * @param  list<string>  $avoid  interest values to leave out
     * @param  list<string>  $preferences  Preference poles, one per axis at most
     * @param  int  $answered  rounds answered, skips not counted
     */
    public function __construct(
        public array $interests = [],
        public array $scores = [],
        public array $avoid = [],
        public ?int $budgetMin = null,
        public ?int $budgetMax = null,
        public array $preferences = [],
        public int $answered = 0,
    ) {}

    /** Learned nothing worth saving: no interest and no price band. */
    public function isEmpty(): bool
    {
        return $this->interests === [] && $this->budgetMin === null && $this->avoid === [];
    }

    /**
     * The brief to ask the suggestion engine with.
     *
     * An avoided interest travels in `avoid` in the tag's own spelling
     * (`interest:gaming`), which the engine excludes by tag and never by the
     * title; see TasteBrief::avoidedInterests().
     *
     * @param  list<int>  $exclude  the products already shown during the rounds
     * @param  string|null  $relationship  who it is for, when "Find a gift" was told (RecipientType)
     */
    public function brief(Market $market, int $limit, array $exclude = [], ?SuggestionProfile $profile = null, ?string $relationship = null): TasteBrief
    {
        return new TasteBrief(
            market: $market,
            interests: $this->interests,
            preferences: $this->preferences,
            budgetMin: $this->budgetMin,
            budgetMax: $this->budgetMax,
            avoid: $this->avoidEntries(),
            relationship: $relationship,
            excludeGroupIds: $exclude,
            limit: $limit,
            profile: $profile,
        );
    }

    /** @return list<string> */
    public function avoidEntries(): array
    {
        return array_map(fn (string $interest) => GiftTags::interest($interest), $this->avoid);
    }

    /**
     * This profile laid over what is already stored about the person.
     *
     * Adds, never wipes: a person described last month and then chosen for
     * today keeps what was said then, with today's findings in front. The one
     * thing that is taken away is a contradiction: an interest learned today
     * comes off the avoid list, and an interest avoided today comes off the
     * interests. Pass an empty array to write this profile on its own.
     *
     * @param  array{interests?: array<mixed>|null, avoid?: array<mixed>|null, preferences?: array<mixed>|null}  $stored
     * @return array{interests: list<string>, avoid: list<string>, preferences: list<string>}
     */
    public function mergedWith(array $stored): array
    {
        $avoidTags = $this->avoidEntries();
        $learnedTags = array_map(fn (string $i) => GiftTags::interest($i), $this->interests);

        $interests = array_values(array_unique(array_filter(
            [...$this->interests, ...array_map('strval', (array) ($stored['interests'] ?? []))],
            fn (string $interest) => $interest !== '' && ! in_array(GiftTags::interest($interest), $avoidTags, true),
        )));

        $avoid = array_values(array_unique(array_filter(
            [...array_map('strval', (array) ($stored['avoid'] ?? [])), ...$avoidTags],
            fn (string $entry) => $entry !== '' && ! in_array(mb_strtolower(trim($entry)), $learnedTags, true),
        )));

        // Today's poles first, then the stored ones on axes today said nothing about.
        $preferences = $this->preferences;
        $axes = array_map(fn (string $p) => Preference::from($p)->axis(), $preferences);

        foreach ((array) ($stored['preferences'] ?? []) as $pole) {
            $preference = Preference::tryFrom((string) $pole);

            if ($preference !== null && ! in_array($preference->axis(), $axes, true)) {
                $preferences[] = $preference->value;
                $axes[] = $preference->axis();
            }
        }

        return [
            'interests' => array_slice($interests, 0, self::MAX_INTERESTS),
            'avoid' => array_slice($avoid, 0, self::MAX_AVOID),
            'preferences' => array_slice($preferences, 0, self::MAX_PREFERENCES),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'interests' => $this->interests,
            'avoid' => $this->avoid,
            'budgetMin' => $this->budgetMin,
            'budgetMax' => $this->budgetMax,
            'preferences' => $this->preferences,
            'answered' => $this->answered,
        ];
    }
}
