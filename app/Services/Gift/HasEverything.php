<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;

/**
 * Presents for someone who has everything: things that get used up or done
 * rather than kept. Tasting boxes, refills, workshops, subscriptions, good
 * chocolate.
 *
 * The owner's request 8 (2026-09-26). A brief with `hasEverything` asks the
 * suggestion engine for these first ({@see SuggestionEngine}), and this class
 * is what both sides of that ask: which words to retrieve on in a market, and
 * whether a product found is one of them.
 *
 * Pure and deterministic, from `resources/content/has-everything-words.php`,
 * whose header says how a word matches and which words were left out on
 * purpose. The matching is InterestGuesser's, reused with one list, so the
 * two word files follow the same rules. No AI: this runs inside the Gift
 * Finder's request. See docs/features/has-everything.md.
 */
final class HasEverything
{
    /** The key the words are filed under inside the reused InterestGuesser. */
    private const KEY = 'has_everything';

    private InterestGuesser $matcher;

    /** @var array<string, list<string>> language => search words */
    private array $search;

    /**
     * @param  array{search: array<string, list<string>>, words: list<string>}|null  $words  null reads the content file
     */
    public function __construct(?array $words = null)
    {
        $words ??= require resource_path('content/has-everything-words.php');

        $this->search = $words['search'];
        $this->matcher = new InterestGuesser(['interests' => [self::KEY => $words['words']], 'not_gifts' => []]);
    }

    /**
     * What the engine retrieves on in this market: whole words in the
     * catalogue's language, one OR each.
     *
     * @return list<string>
     */
    public function searchWords(Market $market): array
    {
        return $this->search[$market->language()] ?? $this->search['en'] ?? [];
    }

    /** Whether this product is something that gets used up or done. */
    public function matches(string $title, ?string $category = null): bool
    {
        return $this->matcher->interests($title, $category) !== [];
    }
}
