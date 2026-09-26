<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\EventType;
use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\Preference;
use App\Enums\RecipientType;
use App\Enums\Vibe;
use App\Models\Recipient;
use App\Models\Wishlist;

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
     * @param  list<string>  $preferences  Preference poles; several axes, never both ends of one
     * @param  list<string>  $values  'sustainable', 'local', 'handmade'
     * @param  list<int>  $excludeGroupIds  already shown, swapped away, or on the list
     * @param  string|null  $query  a typed search, when the person also knows what they want
     */
    public function __construct(
        public Market $market,
        public array $interests = [],
        public ?Vibe $vibe = null,
        public array $preferences = [],
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
            preferences: array_values(array_filter((array) $recipient->preferences)),
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

    /**
     * A person's own wish list, read as the brief for a gift for them.
     *
     * Roadmap step 4, engine G (docs/strategy.md): the list is the best brief
     * there is. Its interests are the ones its products are tagged with (by
     * editors, and by people's lists), its budget band sits around the price
     * of what is on it, and what is on it is excluded, since the list itself is
     * shown first. Nothing about claims is read (invariant 4).
     *
     * Null when the list says too little to go on: no tagged product means no
     * interest, and a brief with none would be a random shelf wearing the
     * list's name.
     */
    public static function fromList(Wishlist $list, int $limit = 4): ?self
    {
        $groups = $list->items()->whereNotNull('group_id')->with('group')->get()
            ->pluck('group')
            ->filter();

        $counts = [];

        foreach ($groups as $group) {
            foreach ([...$group->giftTags(), ...$group->crowdTags()] as $tag) {
                if (str_starts_with($tag, GiftTags::INTEREST.':')) {
                    $interest = substr($tag, strlen(GiftTags::INTEREST) + 1);
                    $counts[$interest] = ($counts[$interest] ?? 0) + 1;
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        $prices = $groups->pluck('min_price')->filter()->sort()->values();
        $median = $prices->isEmpty() ? null : (int) $prices[intdiv($prices->count(), 2)];

        return new self(
            market: $list->market,
            // The three interests most of the list is about.
            interests: array_slice(array_keys($counts), 0, 3),
            // Half to one and a half times the typical price on the list:
            // near what they asked for, without being only what they asked for.
            budgetMin: $median === null ? null : intdiv($median, 2),
            budgetMax: $median === null ? null : intdiv($median * 3, 2),
            occasion: $list->event_type !== null && $list->event_type !== EventType::Other ? $list->event_type->value : null,
            excludeGroupIds: $groups->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            limit: $limit,
        );
    }

    /**
     * The words to keep out of titles: everything in `avoid` except whole
     * interests.
     *
     * @return list<string>
     */
    public function avoidWords(): array
    {
        return array_values(array_filter(
            $this->avoid,
            fn (string $entry) => ! str_starts_with(mb_strtolower(trim($entry)), GiftTags::INTEREST.':'),
        ));
    }

    /**
     * Whole interests to leave out, written in `avoid` as `interest:gaming`.
     *
     * Taste discovery learns "not gaming" from choices (TasteProfiler), and an
     * interest key cannot go into `avoid` as a plain word: `avoid` is matched
     * against titles with ILIKE, so "art" would remove every title containing
     * "smart" or "party", and "gaming" would miss every Dutch title. So an
     * avoided interest is written in the tag's own spelling and excluded by
     * the tag, never by the title. Nobody types `interest:` by hand, so this
     * changes nothing for a word somebody wrote.
     *
     * @return list<string>
     */
    public function avoidedInterests(): array
    {
        $interests = [];

        foreach ($this->avoid as $entry) {
            $entry = mb_strtolower(trim($entry));

            if (str_starts_with($entry, GiftTags::INTEREST.':')) {
                $value = substr($entry, strlen(GiftTags::INTEREST) + 1);

                if (Interest::tryFrom($value) !== null) {
                    $interests[] = $value;
                }
            }
        }

        return array_values(array_unique($interests));
    }

    /**
     * The brief as a plain array, to store (`cove_plans.brief`,
     * `gift_landings.brief`) or to send over the editorial API.
     *
     * Only what describes the person and the present. The market is left out
     * because whatever holds a brief already has one (a plan, a landing page)
     * and two copies can disagree; the limit, the exclusions and the ranking
     * profile are left out because they belong to one run of the engine, not
     * to the brief. Empty fields are dropped, so a stored brief says only
     * what somebody decided.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'relationship' => $this->relationship,
            'interests' => $this->interests,
            'occasion' => $this->occasion,
            'ageBand' => $this->ageBand,
            'budgetMin' => $this->budgetMin,
            'budgetMax' => $this->budgetMax,
            'vibe' => $this->vibe?->value,
            'preferences' => $this->preferences,
            'values' => $this->values,
            'avoid' => $this->avoid,
            'query' => $this->query,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * A brief back from {@see toArray()}, or from anyone who wrote one by hand.
     *
     * Every closed field is checked against the gift vocabulary and an
     * unknown value is dropped rather than kept: a stored brief is read by
     * the engine months later, and a value it does not understand would
     * quietly match nothing. `problems()` names what would be dropped, for
     * the callers (the editorial API) that would rather refuse than drop.
     *
     * Interests are the closed `Interest` values only. The wizard accepts free
     * text too, but a stored brief drives a page nobody is watching, and a
     * typed word there is a guess the engine turns into a text search.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Market $market, int $limit = 4): self
    {
        $min = self::cents($data['budgetMin'] ?? null);
        $max = self::cents($data['budgetMax'] ?? null);

        // A budget written the wrong way round means the same band.
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $query = is_string($data['query'] ?? null) ? trim(mb_substr($data['query'], 0, 100)) : '';

        return new self(
            market: $market,
            interests: self::known($data['interests'] ?? [], Interest::values(), 8),
            vibe: is_string($data['vibe'] ?? null) ? Vibe::tryFrom($data['vibe']) : null,
            preferences: self::known($data['preferences'] ?? [], Preference::values(), 3),
            budgetMin: $min,
            budgetMax: $max,
            avoid: array_slice(array_values(array_unique(array_filter(array_map(
                fn ($word) => is_string($word) ? trim(mb_substr($word, 0, 40)) : '',
                (array) ($data['avoid'] ?? []),
            )))), 0, 10),
            values: self::known($data['values'] ?? [], GiftTags::VALUE_OPTIONS, 3),
            relationship: self::one($data['relationship'] ?? null, RecipientType::values()),
            occasion: self::one($data['occasion'] ?? null, GiftTags::vocabulary()[GiftTags::OCCASION]),
            ageBand: self::one($data['ageBand'] ?? null, GiftTags::AGE_BANDS),
            limit: $limit,
            query: $query === '' ? null : $query,
        );
    }

    /**
     * What {@see fromArray()} would drop from this array, one line per field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string> field => why
     */
    public static function problems(array $data): array
    {
        $closed = [
            'relationship' => RecipientType::values(),
            'occasion' => GiftTags::vocabulary()[GiftTags::OCCASION],
            'ageBand' => GiftTags::AGE_BANDS,
            'vibe' => Vibe::values(),
        ];

        $lists = [
            'interests' => Interest::values(),
            'preferences' => Preference::values(),
            'values' => GiftTags::VALUE_OPTIONS,
        ];

        $problems = [];

        foreach ($closed as $field => $allowed) {
            $value = $data[$field] ?? null;

            if ($value !== null && ! in_array(is_string($value) ? mb_strtolower(trim($value)) : $value, $allowed, true)) {
                $problems[$field] = "`{$field}` takes one of: ".implode(', ', $allowed).'.';
            }
        }

        foreach ($lists as $field => $allowed) {
            $unknown = array_values(array_filter(
                (array) ($data[$field] ?? []),
                fn ($v) => ! is_string($v) || ! in_array(mb_strtolower(trim($v)), $allowed, true),
            ));

            if ($unknown !== []) {
                $problems[$field] = "`{$field}` has values outside the vocabulary (".implode(', ', array_map('strval', array_filter($unknown, 'is_scalar')))
                    .'). It takes: '.implode(', ', $allowed).'.';
            }
        }

        foreach (['budgetMin', 'budgetMax'] as $field) {
            $value = $data[$field] ?? null;

            if ($value !== null && (! is_numeric($value) || (int) $value < 0)) {
                $problems[$field] = "`{$field}` is a whole number of cents, zero or more.";
            }
        }

        return $problems;
    }

    /** Nothing in it that the engine would read as a wish. */
    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function known(mixed $values, array $allowed, int $max): array
    {
        $wanted = array_map(
            fn ($v) => is_string($v) ? mb_strtolower(trim($v)) : '',
            (array) $values,
        );

        return array_slice(array_values(array_unique(array_filter(
            $wanted,
            fn (string $v) => in_array($v, $allowed, true),
        ))), 0, $max);
    }

    /** @param list<string> $allowed */
    private static function one(mixed $value, array $allowed): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : null;
    }

    /** Cents are whole numbers (invariant 7); anything else is not a budget. */
    private static function cents(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value < 0) {
            return null;
        }

        return (int) $value;
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
     * The same brief with another budget, in cents. Its own method because
     * `with()` cannot tell "clear it" from "keep it" for a nullable number.
     */
    public function withBudget(?int $min, ?int $max): self
    {
        return new self(
            market: $this->market,
            interests: $this->interests,
            vibe: $this->vibe,
            preferences: $this->preferences,
            budgetMin: $min,
            budgetMax: $max,
            avoid: $this->avoid,
            values: $this->values,
            relationship: $this->relationship,
            occasion: $this->occasion,
            ageBand: $this->ageBand,
            excludeGroupIds: $this->excludeGroupIds,
            limit: $this->limit,
            profile: $this->profile,
            query: $this->query,
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
     * @param  list<string>|null  $preferences
     * @param  list<string>|null  $values
     * @param  list<int>|null  $excludeGroupIds
     */
    private function with(
        ?array $interests = null,
        ?Vibe $vibe = null,
        ?array $preferences = null,
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
            preferences: $preferences ?? $this->preferences,
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
