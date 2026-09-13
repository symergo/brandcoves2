<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Market;
use App\Models\WishlistItem;
use App\Support\Owner;
use Illuminate\Support\Facades\DB;

/**
 * What somebody's lists say they like: the words in the titles of what they
 * saved, the brands behind those products, and the products themselves so
 * they are not shown again.
 *
 * Read for the search landing (owner's request, 2026-09-13): `/search` with
 * no term showed everybody the same catalogue grid, led by whatever had the
 * most shops. For a visitor who has saved things, that grid is the one place
 * on the site that knows nothing about them. This is the taste the grid is
 * seeded from instead — see {@see SearchService::similarTo()}.
 *
 * ## Titles, not categories
 *
 * The first version matched on brand and category. The owner's list held a
 * kids' backpack whose category is "Kids", and the landing filled with
 * children's books; the book on subway art they also saved was an Amazon
 * item with no catalogue product behind it and counted for nothing. A
 * category is a shelf label — "Boek" covers a thousand products — and an
 * audience like "Kids" is not even that. The title is what the person read
 * when they chose the thing, so the title is what similarity is measured
 * on: its words, stemmed by the same text configuration the search index
 * uses, matched against the offers' search vectors. Every saved item has a
 * title, catalogue or not, so the Amazon book counts too. Brand and
 * category remain as tie-breakers only.
 *
 * Deliberately shallow beyond that: anything cleverer would be a recommender,
 * with its own data, its own drift and its own explanations owed.
 */
final readonly class SavedTaste
{
    /** Titles to read; a list longer than this says nothing sharper. */
    private const TITLES = 30;

    /** Enough brands to have variety, few enough to still look chosen. */
    private const TOP = 5;

    /** Distinct title words to search on; more than this dilutes the ranking. */
    private const LEXEMES = 60;

    /** Live shops asked per landing; each is a request upstream. */
    private const LIVE_TERMS = 2;

    /**
     * @param  list<string>  $lexemes  stemmed words from the saved titles
     * @param  list<string>  $brands  most saved first
     * @param  list<string>  $categories  most saved first
     * @param  list<string>  $identityKeys  what is already on a list
     * @param  list<string>  $liveTerms  short queries for the live shops, from the latest saves
     */
    private function __construct(
        public array $lexemes,
        public array $brands,
        public array $categories,
        public array $identityKeys,
        public array $liveTerms,
    ) {}

    /**
     * Null when there is nothing to go on: no account, or nothing saved
     * that has a title or a brand.
     */
    public static function forOwner(Owner $owner, Market $market): ?self
    {
        if (! $owner->isSignedIn()) {
            return null;
        }

        $items = WishlistItem::query()
            ->whereNotNull('accepted_at')
            ->whereHas('wishlist', fn ($q) => $owner->scope($q))
            ->with('group:id,title,brand,category,identity_key')
            ->latest('id')
            ->limit(self::TITLES)
            ->get(['id', 'group_id', 'wishlist_id', 'snapshot_title']);

        if ($items->isEmpty()) {
            return null;
        }

        $groups = $items->pluck('group')->filter();

        $titles = $items
            ->map(fn (WishlistItem $item) => trim((string) ($item->group?->title ?? $item->snapshot_title)))
            ->filter()
            ->unique()
            ->values();

        $liveTerms = $items
            ->map(fn (WishlistItem $item) => self::liveTerm(
                trim((string) ($item->group?->title ?? $item->snapshot_title)),
                $item->group?->brand,
            ))
            ->filter()
            ->unique()
            ->take(self::LIVE_TERMS)
            ->values()
            ->all();

        $top = fn (string $column): array => $groups
            ->pluck($column)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(self::TOP)
            ->values()
            ->all();

        $lexemes = self::lexemes($titles->all(), $market);
        $brands = $top('brand');

        if ($lexemes === [] && $brands === []) {
            return null;
        }

        return new self(
            lexemes: $lexemes,
            brands: $brands,
            categories: $top('category'),
            identityKeys: $groups->pluck('identity_key')->unique()->values()->all(),
            liveTerms: $liveTerms,
        );
    }

    /**
     * A short query for the live shops, from one saved title.
     *
     * A whole title sent to bol finds the very product that was saved; two
     * of its longest words find its neighbours. The brand leads when there
     * is one ("Sony koptelefoon draadloze"), model numbers and short tokens
     * are dropped, and a title with nothing left gives nothing.
     */
    private static function liveTerm(string $title, ?string $brand): ?string
    {
        $brand = trim((string) $brand);
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_unique(array_filter(
            $words,
            fn (string $w) => mb_strlen($w) >= 4 && ($brand === '' || ! str_contains(mb_strtolower($brand), $w)),
        )));

        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $term = trim($brand.' '.implode(' ', array_slice($words, 0, 2)));

        return $term === '' ? null : $term;
    }

    /**
     * The words of the titles, as the index would store them.
     *
     * Run through Postgres rather than split in PHP, so a title is stemmed,
     * unaccented and stripped of stop words by exactly the configuration the
     * offers' search vectors were built with — "koptelefoons" and
     * "koptelefoon" are one lexeme on both sides. The current market's
     * configuration is used for every title, whichever market it was saved
     * on: the stemmers differ little on product names, and the vectors being
     * matched are this market's.
     *
     * Short and purely numeric tokens are dropped. A model number matches
     * only its own product, and two-letter tokens match everything.
     *
     * @param  list<string>  $titles
     * @return list<string>
     */
    private static function lexemes(array $titles, Market $market): array
    {
        if ($titles === []) {
            return [];
        }

        $sql = implode(' UNION ALL ', array_fill(
            0,
            count($titles),
            'SELECT unnest(tsvector_to_array(to_tsvector(bc_text_config(?), bc_unaccent(?)))) AS lexeme',
        ));

        $bindings = [];

        foreach ($titles as $title) {
            $bindings[] = $market->value;
            $bindings[] = mb_substr($title, 0, 200);
        }

        $seen = [];

        foreach (DB::select("SELECT lexeme, count(*) AS n FROM ({$sql}) t GROUP BY lexeme ORDER BY n DESC, lexeme", $bindings) as $row) {
            $lexeme = (string) $row->lexeme;

            if (mb_strlen($lexeme) < 3 || ctype_digit($lexeme)) {
                continue;
            }

            $seen[] = $lexeme;

            if (count($seen) >= self::LEXEMES) {
                break;
            }
        }

        return $seen;
    }

    /**
     * The lexemes as one OR query, for `@@` and `ts_rank_cd`.
     *
     * Quoted, so a lexeme carrying a hyphen or a dot is one token; cast to
     * `tsquery` rather than parsed by `to_tsquery`, because these are already
     * normalised and a second pass through the stemmer would change them.
     */
    public function tsquery(): string
    {
        return implode(' | ', array_map(
            fn (string $lexeme) => "'".str_replace(['\\', "'"], ['\\\\', "''"], $lexeme)."'",
            $this->lexemes,
        ));
    }
}
