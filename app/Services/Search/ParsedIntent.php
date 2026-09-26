<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Market;
use App\Services\Gift\TasteBrief;

/**
 * What GiftIntentParser read out of a search.
 *
 * Each recognised piece keeps the words it came from (`phrases`), so the page
 * can show them back and offer to drop any one of them: a wrong reading must
 * cost one tap, not a retyped search.
 */
final readonly class ParsedIntent
{
    /**
     * @param  list<string>  $interests  Interest values
     * @param  array<string, string>  $phrases  piece => the words it was read from
     */
    public function __construct(
        public string $original,
        public bool $isGift = false,
        public ?string $relationship = null,
        public array $interests = [],
        public ?string $occasion = null,
        public ?int $budgetMin = null,
        public ?int $budgetMax = null,
        public string $rest = '',
        public array $phrases = [],
        // "Who has everything" was said: prefer what gets used up or done.
        public bool $hasEverything = false,
    ) {}

    public function hasBudget(): bool
    {
        return $this->budgetMin !== null || $this->budgetMax !== null;
    }

    public function isEmpty(): bool
    {
        return ! $this->isGift && ! $this->hasBudget();
    }

    /** The brief the suggestion engine answers, with what is left as its search words. */
    public function toBrief(Market $market, int $limit): TasteBrief
    {
        return new TasteBrief(
            market: $market,
            interests: $this->interests,
            budgetMin: $this->budgetMin,
            budgetMax: $this->budgetMax,
            relationship: $this->relationship,
            occasion: $this->occasion,
            limit: $limit,
            query: $this->rest === '' ? null : $this->rest,
            hasEverything: $this->hasEverything,
        );
    }

    /**
     * The search with one piece taken out: what "remove this" on a chip runs.
     */
    public function without(string $piece): string
    {
        $phrase = $this->phrases[$piece] ?? null;

        if ($phrase === null) {
            return $this->original;
        }

        // The parser read dashes as '-'; so must this, or "€30–€50" never matches.
        $original = str_replace(['–', '—', '−'], '-', $this->original);
        $text = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/iu', ' ', $original, 1) ?? $original;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
